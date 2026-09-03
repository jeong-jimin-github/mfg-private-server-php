#!/usr/bin/env python3
"""Compare PHP HandEvaluator results with the Python reference scorer."""

from __future__ import annotations

import json
import sys
from pathlib import Path


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("usage: evaluator_reference_parity.py REFERENCE_DIR PHP_JSON")
    reference = Path(sys.argv[1]).resolve()
    php = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
    sys.path.insert(0, str(reference))
    import mahjong as m  # type: ignore

    for row in php:
        c = row["case"]
        hand = [m.pai_to_idx(int(x)) for x in c["hand"]]
        melds = [
            m.Meld(str(md["kind"]), [m.pai_to_idx(int(x)) for x in md["tiles"]])
            for md in c.get("melds", [])
        ]
        ctx = m.WinContext(
            hand,
            melds,
            m.pai_to_idx(int(c["win"])),
            bool(c["tsumo"]),
            int(c["seat"]),
            int(c["round"]),
            riichi=bool(c.get("riichi", False)),
            double_riichi=bool(c.get("double", False)),
            ippatsu=bool(c.get("ippatsu", False)),
            dora_indicators=[m.pai_to_idx(int(x)) for x in c.get("dora", [])],
            ura_indicators=[m.pai_to_idx(int(x)) for x in c.get("ura", [])],
            taku=m.TONPU,
        )
        expected = m.evaluate(ctx)
        assert expected is not None, (c["name"], "Python evaluator rejected")
        actual = row["result"]
        for key in ("han", "fu", "rank", "dora", "yakuman"):
            assert int(actual[key]) == int(expected[key]), (
                c["name"], key, actual[key], expected[key], actual, expected
            )

    print(f"HandEvaluator PHP/Python parity OK cases={len(php)}")


if __name__ == "__main__":
    main()
