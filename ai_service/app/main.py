"""KrishiHat AI service (FastAPI).  Run:  uvicorn app.main:app --port 8001"""
from __future__ import annotations

import hmac
import os
import datetime as dt

from fastapi import Depends, FastAPI, Header, HTTPException
from pydantic import BaseModel, Field

from .forecasting import InsufficientDataError, forecast_prices

app = FastAPI(title="KrishiHat AI Service", version="1.0.0")


def require_api_key(x_api_key: str | None = Header(default=None)) -> None:
    """If AI_SERVICE_KEY is set, every request must send the same key."""
    expected = os.environ.get("AI_SERVICE_KEY", "")
    if expected and not hmac.compare_digest(x_api_key or "", expected):
        raise HTTPException(status_code=401, detail="Invalid API key")


class PricePoint(BaseModel):
    date: dt.date
    price: float = Field(gt=0, le=10_000_000)


class ForecastRequest(BaseModel):
    crop: str = Field(min_length=1, max_length=200)
    unit: str | None = Field(default=None, max_length=50)
    history: list[PricePoint] = Field(max_length=5000)
    horizon_days: int = Field(default=7, ge=1, le=30)
    from_date: dt.date | None = None


@app.get("/health")
def health():
    return {"status": "ok", "service": "krishihat-ai"}


@app.post("/forecast", dependencies=[Depends(require_api_key)])
def forecast(req: ForecastRequest):
    records = [{"date": p.date.isoformat(), "price": p.price} for p in req.history]
    try:
        result = forecast_prices(records, req.horizon_days, req.from_date)
    except InsufficientDataError as exc:
        raise HTTPException(status_code=422, detail={"code": "insufficient_data", "message": str(exc)})

    return {
        "crop": req.crop,
        "unit": req.unit,
        "model": result.model,
        "observations": result.observations,
        "last_observed": {"date": result.last_observed_date.isoformat(), "price": result.last_observed_price},
        "forecast": [
            {"date": p.date.isoformat(), "price": p.price, "lower": p.lower, "upper": p.upper}
            for p in result.forecast
        ],
        "trend": {"direction": result.trend_direction, "change_percent": result.trend_change_percent},
        "quality": {
            "confidence": result.confidence,
            "backtest_mae": result.backtest_mae,
            "backtest_mape_percent": result.backtest_mape,
        },
        "warnings": result.warnings,
    }
