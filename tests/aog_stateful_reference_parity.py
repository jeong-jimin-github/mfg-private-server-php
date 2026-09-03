#!/usr/bin/env python3
"""Cross-check a stateful AOG session against the Python reference server."""

from __future__ import annotations

import base64
import json
import os
import re
import sys
import tempfile
from pathlib import Path
from xml.etree import ElementTree as ET


def parse(xml: str) -> ET.Element:
    return ET.fromstring(xml)


def find_mode_tenbo(menu: ET.Element, gmode: int) -> int:
    for node in menu.findall("./menudata/playmode_list/mode"):
        if int(node.findtext("gmode") or 0) == gmode:
            return int(node.findtext("tenbo") or 0)
    raise AssertionError(f"gmode {gmode} missing")


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("usage: aog_stateful_reference_parity.py REFERENCE_DIR PHP_JSON")

    reference = Path(sys.argv[1]).resolve()
    php = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))

    os.environ["VFG_EVENT_TAKU"] = "min"
    os.environ["VFG_GACHA_ALL"] = "0"
    sys.path.insert(0, str(reference))
    import server  # type: ignore

    old_db, old_host, old_port = server.DB, server.HOST, server.PORT
    old_matches = dict(server.MATCHES)
    old_tables = dict(server.TABLES)
    try:
        with tempfile.TemporaryDirectory() as tmp:
            server.DB = server.ProfileDB(Path(tmp) / "save.json")
            server.HOST = "127.0.0.1"
            server.PORT = 8080
            server.MATCHES.clear()
            server.TABLES.clear()

            refid = "PARITY-STATE"
            name = "PARITY"
            login = parse(server.handle_login({"user_id": refid}))
            pcuid = login.findtext("./auth/session_id") or ""
            server.handle_create_player({"user_id": refid, "name": name})
            menu = parse(server.handle_get_menudata({"pcuid": pcuid}))
            mid = int(menu.findtext("./menudata/mpdata/mid") or 0)

            player_game = '{"SelectChara":0}'
            custom = "custom-state"
            server.handle_client_state_write(
                {
                    "mid": str(mid),
                    "kind": "player_game",
                    "data": base64.b64encode(player_game.encode()).decode(),
                }
            )
            server.handle_client_state_write(
                {
                    "mid": str(mid),
                    "kind": "customize_item",
                    "data": base64.b64encode(custom.encode()).decode(),
                }
            )
            read = parse(
                server.handle_client_state_read(
                    {"mid": str(mid), "one_kind": "player_game"}
                )
            )
            states = {}
            for state in read.findall("state"):
                states[state.attrib.get("kind", "")] = base64.b64decode(
                    (state.findtext("data") or "").encode("ascii")
                ).decode("utf-8")

            entry = parse(server.handle_entry_game({"pcuid": pcuid, "gmode": "4"}))
            e = entry.find("entry")
            assert e is not None
            tid = int(e.findtext("tid") or 0)
            must = f"VFG:J:A:A:2025122300/{pcuid}/{tid}/0/1/0"
            gget = parse(
                server.handle_gget({"pcuid": pcuid, "ready": "0", "must": must})
            )
            m = gget.find("./game/mwait")
            assert m is not None

            human_states = {}
            cs = m.find("./mend/player_0/client_states")
            if cs is not None:
                for state in cs.findall("state"):
                    human_states[state.attrib.get("kind", "")] = base64.b64decode(
                        (state.findtext("data") or "").encode("ascii")
                    ).decode("utf-8")

            end = parse(server.handle_end_or_kiken({"pcuid": pcuid}))
            mg = end.find("mgresult")
            assert mg is not None
            players = []
            for i in (0, 1):
                p = mg.find(f"player_{i}")
                assert p is not None
                players.append(
                    {
                        "rank": int(p.findtext("rank") or 0),
                        "score": int(p.findtext("score") or 0),
                        "uma": int(p.findtext("uma") or 0),
                    }
                )

            expected = {
                "session_hex": re.fullmatch(r"[0-9a-f]{32}", pcuid) is not None,
                "menu": {
                    "name": menu.findtext("./menudata/mpdata/name") or "",
                    "mid_positive": mid > 0,
                    "nima_tenbo": find_mode_tenbo(menu, 4),
                },
                "state": {
                    "count": len(states),
                    "player_game": states.get("player_game"),
                },
                "entry": {
                    "gserv_id": int(e.findtext("gserv_id") or 0),
                    "tid": tid,
                    "pindex": int(e.findtext("pindex") or 0),
                    "next_sno": int(e.findtext("next_sno") or 0),
                    "gserv_url": e.findtext("gserv_url") or "",
                    "pay_mode": int(e.findtext("pay_mode") or 0),
                    "gmode": int(e.findtext("gmode") or 0),
                    "ste_limit_time": int(e.findtext("ste_limit_time") or 0),
                    "naki_limit_time": int(e.findtext("naki_limit_time") or 0),
                },
                "matching": {
                    "status": int(m.findtext("status") or 0),
                    "pnum": int(m.findtext("pnum") or 0),
                    "cpu_num": int(m.findtext("cpu_num") or 0),
                    "pindex": int(m.findtext("pindex") or 0),
                    "name": m.findtext("./epdata_0/name") or "",
                    "mid_positive": int(m.findtext("./epdata_0/mid") or 0) > 0,
                    "human_ptype": int(m.find("./mend/player_0").attrib.get("ptype", "0")),
                    "human_zaseki": int(m.findtext("./mend/player_0/zaseki") or 0),
                    "cpu_ptype": int(m.find("./mend/player_1").attrib.get("ptype", "0")),
                    "cpu_zaseki": int(m.findtext("./mend/player_1/zaseki") or 0),
                    "cpu_name": m.findtext("./mend/player_1/cpu_name") or "",
                    "states": human_states,
                },
                "end": {
                    "gmode": int(mg.findtext("gmode") or 0),
                    "players": players,
                },
            }

            assert php == expected, json.dumps(
                {"php": php, "python": expected}, ensure_ascii=False, indent=2
            )
    finally:
        server.DB, server.HOST, server.PORT = old_db, old_host, old_port
        server.MATCHES.clear()
        server.MATCHES.update(old_matches)
        server.TABLES.clear()
        server.TABLES.update(old_tables)

    print("stateful PHP/Python AOG parity OK")


if __name__ == "__main__":
    main()
