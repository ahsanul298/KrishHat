"""
KrishiHat price forecasting.

Input : a list of (date, price) observations for ONE crop.
Output: a short-term forecast with an uncertainty band.

How it works (kept deliberately simple and honest):
  1. Several small models are fitted: last value, recent average,
     linear trend (scikit-learn LinearRegression) and trend + weekly pattern.
  2. Each model is back-tested on the most recent observations
     (train on the past, predict the next one, measure the error).
  3. The model with the lowest back-test error is used for the forecast.
  4. With too little data the service refuses (InsufficientDataError)
     instead of inventing numbers.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from datetime import date, timedelta

import numpy as np
import pandas as pd
from sklearn.linear_model import LinearRegression

MIN_POINTS_ABSOLUTE = 3      # below this: refuse
MIN_POINTS_TREND = 6         # below this: only "last value" / "recent average"
MIN_POINTS_WEEKLY = 28       # below this: no weekly pattern
MAX_MOVE_RATIO = 0.5         # forecast is kept within ±50 % of the last price
RECENT_WINDOW = 30           # trend is fitted on the latest 30 observations


class InsufficientDataError(ValueError):
    """Raised when there are not enough valid observations to forecast."""


@dataclass
class ForecastPoint:
    date: date
    price: float
    lower: float
    upper: float


@dataclass
class ForecastResult:
    model: str
    observations: int
    last_observed_date: date
    last_observed_price: float
    forecast: list[ForecastPoint]
    backtest_mae: float | None
    backtest_mape: float | None
    confidence: str
    trend_direction: str
    trend_change_percent: float
    warnings: list[str] = field(default_factory=list)


# --------------------------------------------------------------------------
# data preparation
# --------------------------------------------------------------------------
def prepare_observations(records: list[dict]) -> pd.DataFrame:
    """Clean raw records -> one price per day, sorted by date."""
    df = pd.DataFrame(records)
    if df.empty or not {"date", "price"} <= set(df.columns):
        raise InsufficientDataError("কোনো বৈধ (date, price) ডেটা পাওয়া যায়নি।")

    df["date"] = pd.to_datetime(df["date"], errors="coerce", format="mixed")
    df["price"] = pd.to_numeric(df["price"], errors="coerce")
    df = df.dropna(subset=["date", "price"])
    df = df[df["price"] > 0]
    df["date"] = df["date"].dt.normalize()
    df = df.groupby("date", as_index=False)["price"].mean().sort_values("date")
    df = df.reset_index(drop=True)

    if len(df) < MIN_POINTS_ABSOLUTE:
        raise InsufficientDataError(
            f"পূর্বাভাসের জন্য কমপক্ষে {MIN_POINTS_ABSOLUTE}টি ভিন্ন তারিখের দাম লাগবে, "
            f"পাওয়া গেছে {len(df)}টি।"
        )

    df["x"] = (df["date"] - df["date"].iloc[0]).dt.days.astype(float)
    return df


# --------------------------------------------------------------------------
# models: each takes (train_x, train_y, dates_train, predict_x, dates_pred)
# --------------------------------------------------------------------------
def _model_last_value(x, y, d, px, pd_):
    return np.full(len(px), y[-1], dtype=float)


def _model_recent_mean(x, y, d, px, pd_):
    return np.full(len(px), float(np.mean(y[-3:])), dtype=float)


def _model_linear_trend(x, y, d, px, pd_):
    x, y = x[-RECENT_WINDOW:], y[-RECENT_WINDOW:]
    if len(x) < 3 or np.ptp(x) == 0:
        return _model_last_value(x, y, d, px, pd_)
    reg = LinearRegression().fit(((x - x.mean()) / max(np.ptp(x), 1)).reshape(-1, 1), y)
    px_scaled = ((np.asarray(px) - x.mean()) / max(np.ptp(x), 1)).reshape(-1, 1)
    return reg.predict(px_scaled)


def _weekly_features(x, dates, x_ref, span):
    dow = pd.DatetimeIndex(dates).dayofweek.to_numpy()
    return np.column_stack([
        (np.asarray(x) - x_ref) / span,
        np.sin(2 * np.pi * dow / 7),
        np.cos(2 * np.pi * dow / 7),
    ])


def _model_trend_weekly(x, y, d, px, pd_):
    x, y, d = x[-90:], y[-90:], d[-90:]
    span = max(np.ptp(x), 1)
    ref = x.mean()
    reg = LinearRegression().fit(_weekly_features(x, d, ref, span), y)
    return reg.predict(_weekly_features(px, pd_, ref, span))


MODELS = {
    "last_value": _model_last_value,
    "recent_average": _model_recent_mean,
    "linear_trend": _model_linear_trend,
    "trend_weekly": _model_trend_weekly,
}


def _available_models(n: int, span_days: float) -> list[str]:
    names = ["last_value", "recent_average"]
    if n >= MIN_POINTS_TREND:
        names.append("linear_trend")
    if n >= MIN_POINTS_WEEKLY and span_days >= 28:
        names.append("trend_weekly")
    return names


def _backtest(df: pd.DataFrame, name: str) -> list[float]:
    """Rolling one-step-ahead errors on the latest observations."""
    n = len(df)
    k = max(1, min(8, n // 3))
    errors = []
    for i in range(n - k, n):
        train = df.iloc[:i]
        if len(train) < 2:
            continue
        pred = MODELS[name](
            train["x"].to_numpy(), train["price"].to_numpy(), train["date"].to_numpy(),
            np.array([df["x"].iloc[i]]), df["date"].iloc[[i]].to_numpy(),
        )[0]
        errors.append(float(pred - df["price"].iloc[i]))
    return errors


# --------------------------------------------------------------------------
# public API
# --------------------------------------------------------------------------
def forecast_prices(records: list[dict], horizon_days: int = 7,
                    from_date: date | None = None) -> ForecastResult:
    df = prepare_observations(records)
    n = len(df)
    warnings: list[str] = []

    first_date = df["date"].iloc[0]
    last_date = df["date"].iloc[-1].date()
    last_price = float(df["price"].iloc[-1])
    span_days = float(df["x"].iloc[-1])

    # --- choose the model with the lowest back-test error ----------------
    scores = {}
    for name in _available_models(n, span_days):
        errs = _backtest(df, name)
        if errs:
            scores[name] = (float(np.mean(np.abs(errs))), errs)
    if not scores:  # extremely small series
        scores["last_value"] = (0.0, [])
    best = min(scores, key=lambda m: scores[m][0])  # dict order = simplest first on ties
    mae, errs = scores[best]
    mape = float(np.mean([abs(e) / p for e, p in zip(errs, df["price"].iloc[-len(errs):])]) * 100) if errs else None
    sigma = float(np.std(errs)) if len(errs) > 1 else (mae if mae else last_price * 0.05)
    sigma = max(sigma, mae, last_price * 0.01)

    # --- forecast ----------------------------------------------------------
    start = from_date or date.today()
    future_dates = [start + timedelta(days=i) for i in range(1, horizon_days + 1)]
    future_ts = pd.to_datetime(future_dates)
    future_x = (future_ts - first_date).days.to_numpy().astype(float)

    raw = MODELS[best](df["x"].to_numpy(), df["price"].to_numpy(), df["date"].to_numpy(),
                       future_x, future_ts.to_numpy())

    lo_bound, hi_bound = last_price * (1 - MAX_MOVE_RATIO), last_price * (1 + MAX_MOVE_RATIO)
    clipped = np.clip(raw, lo_bound, hi_bound)
    if not np.allclose(raw, clipped):
        warnings.append("পূর্বাভাস অস্বাভাবিক বেশি পরিবর্তন দেখাচ্ছিল, তাই শেষ দামের ±৫০% সীমায় রাখা হয়েছে।")

    points = []
    for i, (d_, p_) in enumerate(zip(future_dates, clipped), start=1):
        width = 1.28 * sigma * (1 + 0.05 * i)       # ~80 % band, widening with distance
        points.append(ForecastPoint(d_, round(float(p_), 2),
                                    round(max(0.0, float(p_) - width), 2),
                                    round(float(p_) + width, 2)))

    # --- summary -----------------------------------------------------------
    change = (points[-1].price - last_price) / last_price * 100
    direction = "up" if change > 1.5 else "down" if change < -1.5 else "stable"

    if n < MIN_POINTS_TREND:
        confidence = "low"
        warnings.append(f"ডেটা খুব কম ({n}টি), তাই শুধু সাধারণ গড়/শেষ দামের ভিত্তিতে অনুমান করা হয়েছে।")
    elif mape is None or mape > 15:
        confidence = "low"
    elif n >= 30 and mape < 7:
        confidence = "high"
    else:
        confidence = "medium"

    if (pd.Timestamp(start) - df["date"].iloc[-1]).days > 14:
        warnings.append("সর্বশেষ দামের তথ্য ১৪ দিনের বেশি পুরনো, পূর্বাভাসের নির্ভরযোগ্যতা কম।")

    return ForecastResult(
        model=best, observations=n, last_observed_date=last_date, last_observed_price=round(last_price, 2),
        forecast=points,
        backtest_mae=round(mae, 3) if errs else None,
        backtest_mape=round(mape, 2) if mape is not None else None,
        confidence=confidence, trend_direction=direction, trend_change_percent=round(change, 2),
        warnings=warnings,
    )
