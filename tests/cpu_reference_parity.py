#!/usr/bin/env python3
"""Compare deterministic PHP CPU call/danger decisions with Python Table AI."""

from __future__ import annotations

import json
import sys
from pathlib import Path


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("usage: cpu_reference_parity.py REFERENCE_DIR PHP_JSON")
    reference = Path(sys.argv[1]).resolve()
    rows = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
    sys.path.insert(0, str(reference))
    import mahjong as m  # type: ignore
    import taikyoku  # type: ignore

    for row in rows:
        c = row["case"]
        s = c["state"]
        t = taikyoku.Table(int(s["taku"]), human_seat=0, seed=1)
        t.seats = int(s["seats"])
        t.kyoku_index = int(s["kyoku_index"])
        t.hands = [[int(x) for x in h] for h in s["hands"]]
        t.melds = [
            [m.Meld(str(md["kind"]), [int(x) for x in md["tiles"]]) for md in seat]
            for seat in s["melds"]
        ]
        t.discards = [[int(x) for x in ds] for ds in s["discards"]]
        t.discard_log = [(int(a), int(b)) for a, b in s["discard_log"]]
        t.riichi = [bool(x) for x in s["riichi"]]
        t.riichi_at = [int(x) for x in s["riichi_at"]]
        t.drawn = [None if x is None else int(x) for x in s["drawn"]]
        t.scores = [int(x) for x in s["scores"]]
        t.wall = [int(x) for x in s["wall"]]
        t.dora_ind = [int(x) for x in s["dora_ind"]]
        t.dora_open = int(s["dora_open"])

        seat = int(c["seat"])
        op = c["op"]
        if op == "danger":
            expected = t._danger(seat, int(c["tile"]))
        elif op == "pon":
            expected = t._cpu_wants_pon(seat, int(c["tile"]))
        elif op == "chi":
            expected = t._cpu_pick_chi(
                seat, int(c["tile"]), [[int(x) for x in opt] for opt in c["opts"]]
            )
        elif op == "kan":
            expected = t._cpu_wants_kan(seat, int(c["tile"]), int(c["type"]))
        else:
            raise AssertionError((c["name"], op))

        actual = row["value"]
        assert actual == expected, (c["name"], op, actual, expected)

    print(f"CPU AI PHP/Python parity OK cases={len(rows)}")


if __name__ == "__main__":
    main()
