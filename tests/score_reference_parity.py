#!/usr/bin/env python3
"""Exhaustively compare PHP score math with the Python reference engine."""

from __future__ import annotations

import json
import sys
from pathlib import Path


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("usage: score_reference_parity.py REFERENCE_DIR PHP_JSON")
    reference = Path(sys.argv[1]).resolve()
    php = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
    sys.path.insert(0, str(reference))
    import mahjong as m  # type: ignore

    for row in php["ranks"]:
        han, fu, yakuman = int(row["han"]), int(row["fu"]), int(row["yakuman"])
        assert row["base"] == m.base_score(han, fu), ("base", han, fu, row["base"], m.base_score(han, fu))
        assert row["rank"] == m.han_rank(han, fu, yakuman), (
            "rank", han, fu, yakuman, row["rank"], m.han_rank(han, fu, yakuman)
        )

    for row in php["payments"]:
        taku, rank, fu = int(row["taku"]), int(row["rank"]), int(row["fu"])
        oya, tsumo = bool(row["oya"]), bool(row["tsumo"])
        expected_base = m.base_score_rank(rank, fu)
        expected = m.payments(taku, rank, fu, oya, tsumo)
        actual = (int(row["total"]), int(row["ko"]), int(row["oya_payment"]))
        assert row["base"] == expected_base, ("base-rank", taku, rank, fu, row["base"], expected_base)
        assert actual == expected, ("payments", taku, rank, fu, oya, tsumo, actual, expected)

    print(
        "ScoreMath PHP/Python parity OK "
        f"ranks={len(php['ranks'])} payments={len(php['payments'])}"
    )


if __name__ == "__main__":
    main()
