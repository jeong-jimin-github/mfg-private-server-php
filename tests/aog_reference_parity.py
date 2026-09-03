#!/usr/bin/env python3
"""Compare deterministic PHP AOG responses against the Python reference server."""

from __future__ import annotations

import base64
import json
import os
import sys
import tempfile
from pathlib import Path
from xml.etree import ElementTree as ET


def canonical(node: ET.Element):
    text = (node.text or "").strip()
    return (
        node.tag,
        tuple(sorted(node.attrib.items())),
        text,
        tuple(canonical(child) for child in list(node)),
    )


def info_payloads(xml: str):
    root = ET.fromstring(xml)
    service = root.find("serv_st/code")
    assert service is not None and (service.text or "").strip() == "0"
    expire = root.findtext("expire_seconds")
    payloads = {}
    for node in root.findall("info_data"):
        raw = base64.b64decode((node.text or "").encode("ascii"))
        payloads[node.attrib["kind"]] = json.loads(raw.decode("utf-8"))
    return expire, payloads


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("usage: aog_reference_parity.py REFERENCE_DIR PHP_JSON")

    reference = Path(sys.argv[1]).resolve()
    php = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))

    # These globals are consumed while server.py is imported.
    os.environ["VFG_EVENT_TAKU"] = "min"
    os.environ["VFG_GACHA_ALL"] = "0"
    sys.path.insert(0, str(reference))
    import server  # type: ignore

    old_db, old_host, old_port = server.DB, server.HOST, server.PORT
    try:
        with tempfile.TemporaryDirectory() as tmp:
            server.DB = server.ProfileDB(Path(tmp) / "save.json")
            server.HOST = "127.0.0.1"
            server.PORT = 8080

            expected = {
                "appli_boot": server.handle_appli_boot({}),
                "appli_info": server.handle_appli_info({}),
                "get_menudata": server.handle_get_menudata({}),
                "keep_alive": server.handle_keep_alive({}),
                "get_jongstone_info": server.handle_get_jongstone_info({}),
                "get_mg": server.handle_get_mg({}),
                "mission_date": server.handle_mission_date({}),
                "player_record": server.handle_player_record({}),
                "get_record": server.handle_player_record({}),
                "get_haifu_list": server.handle_get_haifu_list({}),
                "get_haifu_data": server.xml_response(),
                "present_done": server.handle_present_done({"done_ids": "10,20"}),
                "competition_entry": server.handle_competition_entry({}),
                "chk_tabooword": server.handle_chk_tabooword({"str": "PARITY-NAME"}),
                "end_show": server.handle_end_show(
                    {
                        "voltage": "1234",
                        "contribute_percent": "87",
                        "bonus": "45",
                    }
                ),
            }

            assert set(php) == set(expected), (set(php), set(expected))
            for name, reference_xml in expected.items():
                php_xml = php[name]
                if name == "appli_info":
                    assert info_payloads(php_xml) == info_payloads(reference_xml), name
                else:
                    assert canonical(ET.fromstring(php_xml)) == canonical(
                        ET.fromstring(reference_xml)
                    ), name
    finally:
        server.DB, server.HOST, server.PORT = old_db, old_host, old_port

    print("deterministic PHP/Python AOG response parity OK")


if __name__ == "__main__":
    main()
