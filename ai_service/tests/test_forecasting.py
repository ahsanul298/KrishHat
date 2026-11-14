import math
from datetime import date, timedelta

import pytest

from app.forecasting import InsufficientDataError, forecast_prices

START = date(2026, 8, 1)


def series(n, fn):
    return [{"date": (START + timedelta(days=i)).isoformat(), "price": fn(i)} for i in range(n)]


def test_refuses_when_too_little_data():
    with pytest.raises(InsufficientDataError):
        forecast_prices(series(2, lambda i: 30))


def test_ignores_bad_rows_then_refuses():
    rows = [{"date": "not-a-date", "price": 30}, {"date": "2026-08-01", "price": -5},
            {"date": "2026-08-02", "price": None}, {"date": "2026-08-03", "price": 30}]
    with pytest.raises(InsufficientDataError):
        forecast_prices(rows)


def test_rising_prices_give_upward_forecast():
    res = forecast_prices(series(40, lambda i: 30 + 0.5 * i), horizon_days=7, from_date=START + timedelta(days=39))
    assert res.trend_direction == "up"
    assert res.forecast[0].price > 40
    assert res.forecast[-1].price > res.forecast[0].price
    assert len(res.forecast) == 7


def test_falling_prices_give_downward_forecast():
    res = forecast_prices(series(40, lambda i: 80 - 0.6 * i), horizon_days=7, from_date=START + timedelta(days=39))
    assert res.trend_direction == "down"


def test_flat_prices_stay_stable():
    res = forecast_prices(series(30, lambda i: 50), horizon_days=5, from_date=START + timedelta(days=29))
    assert res.trend_direction == "stable"
    assert all(abs(p.price - 50) < 1 for p in res.forecast)


def test_band_contains_forecast_and_is_not_negative():
    res = forecast_prices(series(35, lambda i: 40 + 3 * math.sin(i / 3)), from_date=START + timedelta(days=34))
    for p in res.forecast:
        assert 0 <= p.lower <= p.price <= p.upper


def test_small_series_is_low_confidence_with_warning():
    res = forecast_prices(series(4, lambda i: 30 + i), from_date=START + timedelta(days=3))
    assert res.confidence == "low"
    assert res.warnings


def test_weekly_pattern_is_detected():
    # Market is dearer on weekday index 4 (every 7th day) – a clear weekly cycle
    res = forecast_prices(series(70, lambda i: 40 + (6 if i % 7 == 4 else 0)),
                          horizon_days=14, from_date=START + timedelta(days=69))
    assert res.model == "trend_weekly"
    assert max(p.price for p in res.forecast) - min(p.price for p in res.forecast) > 3


def test_duplicate_days_are_averaged():
    rows = series(10, lambda i: 30) + [{"date": START.isoformat(), "price": 40}]
    res = forecast_prices(rows, from_date=START + timedelta(days=9))
    assert res.observations == 10


def test_extreme_extrapolation_is_clipped():
    res = forecast_prices(series(10, lambda i: 10 + i * 20), horizon_days=30, from_date=START + timedelta(days=9))
    last = res.last_observed_price
    assert all(p.price <= last * 1.5 + 0.01 for p in res.forecast)
    assert any("৫০%" in w for w in res.warnings)
