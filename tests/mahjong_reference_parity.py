#!/usr/bin/env python3
"""Cross-check PHP Mahjong core math against the Python reference engine."""

from __future__ import annotations

import json
import sys
from pathlib import Path


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("usage: mahjong_reference_parity.py REFERENCE_DIR PHP_JSON")
    reference = Path(sys.argv[1]).resolve()
    php = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))
    sys.path.insert(0, str(reference))
    import mahjong as m  # type: ignore

    for taku in range(4):
        row = php["taku"][str(taku)]
        assert row["seats"] == m.SEATS_OF[taku], (taku, "seats", row["seats"], m.SEATS_OF[taku])
        assert row["kyoku"] == m.KYOKU_COUNT[taku], (taku, "kyoku")
        assert row["start_score"] == m.START_SCORE[taku], (taku, "start_score")
        assert row["live"] == m.live_kinds(taku), (taku, "live")
        expected_dora = {str(i): m.dora_from_indicator(i, taku) for i in m.live_kinds(taku)}
        assert row["dora"] == expected_dora, (taku, "dora", row["dora"], expected_dora)

    for sample in php["samples"]:
        taku = int(sample["taku"])
        hand = [int(x) for x in sample["hand"]]
        counts = m.counts_of(hand)
        shanten = m.shanten(counts, 0, taku)
        waits = m.waits_of(counts, 0, taku)
        improves = []
        for tile in m.live_kinds(taku):
            if counts[tile] >= 4:
                continue
            counts[tile] += 1
            if m.shanten(counts, 0, taku) < shanten:
                improves.append(tile)
            counts[tile] -= 1
        assert sample["shanten"] == shanten, (taku, hand, "shanten", sample["shanten"], shanten)
        assert sample["waits"] == waits, (taku, hand, "waits", sample["waits"], waits)
        assert sample["improves"] == improves, (taku, hand, "improves", sample["improves"], improves)

    for idx, pai, roundtrip, red_roundtrip in php["tile_roundtrip"]:
        assert pai == m.idx_to_pai(idx), (idx, pai, m.idx_to_pai(idx))
        assert roundtrip == m.pai_to_idx(pai) == idx, (idx, pai, roundtrip)
        assert red_roundtrip == m.pai_to_idx(pai + 64) == idx, (idx, pai, red_roundtrip)

    print(f"Mahjong PHP/Python core parity OK samples={len(php['samples'])}")


if __name__ == "__main__":
    main()
