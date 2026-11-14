from datetime import date, timedelta

from fastapi.testclient import TestClient

from app.main import app

client = TestClient(app)
START = date(2026, 9, 1)


def payload(n=30, **extra):
    return {
        "crop": "আলু", "unit": "kg", "horizon_days": 7,
        "from_date": (START + timedelta(days=n - 1)).isoformat(),
        "history": [{"date": (START + timedelta(days=i)).isoformat(), "price": 28 + i * 0.2} for i in range(n)],
        **extra,
    }


def test_health():
    assert client.get("/health").json()["status"] == "ok"


def test_forecast_ok():
    r = client.post("/forecast", json=payload())
    assert r.status_code == 200
    body = r.json()
    assert body["crop"] == "আলু"
    assert len(body["forecast"]) == 7
    assert body["trend"]["direction"] == "up"
    assert set(body["quality"]) == {"confidence", "backtest_mae", "backtest_mape_percent"}


def test_insufficient_data_is_422_with_code():
    r = client.post("/forecast", json=payload(n=2))
    assert r.status_code == 422
    assert r.json()["detail"]["code"] == "insufficient_data"


def test_validation_rejects_bad_input():
    assert client.post("/forecast", json=payload(horizon_days=99)).status_code == 422
    bad = payload()
    bad["history"][0]["price"] = -1
    assert client.post("/forecast", json=bad).status_code == 422


def test_api_key_is_enforced_when_configured(monkeypatch):
    monkeypatch.setenv("AI_SERVICE_KEY", "secret")
    assert client.post("/forecast", json=payload()).status_code == 401
    assert client.post("/forecast", json=payload(), headers={"X-API-Key": "wrong"}).status_code == 401
    assert client.post("/forecast", json=payload(), headers={"X-API-Key": "secret"}).status_code == 200
    assert client.get("/health").status_code == 200
