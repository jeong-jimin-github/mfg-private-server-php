#!/usr/bin/env python3
"""Cross-check deterministic e-Amusement/XRPC responses with Python reference."""

from __future__ import annotations

import json
import os
import sys
import tempfile
from pathlib import Path
from xml.etree import ElementTree as ET

MODEL = "VFG:J:A:A:2025122300"
BASE = "http://127.0.0.1:8080"
CARD = "E0047CC78DFBA459"


def canonical(xml: str):
    root = ET.fromstring(xml)

    def walk(node: ET.Element):
        attrs = []
        for key, value in sorted(node.attrib.items()):
            if key in {"refid", "dataid"}:
                value = "<REFID>"
            elif key in {"time", "lastupdate"}:
                value = "<TIME>"
            attrs.append((key, value))
        text = (node.text or "").strip()
        if node.tag == "sessid" and text:
            text = "<SESSID>"
        return (node.tag, tuple(attrs), text, tuple(walk(child) for child in list(node)))

    return walk(root)


def module_xml(module: str, **attrs: str) -> str:
    rendered = " ".join(f'{k}="{v}"' for k, v in attrs.items())
    return f"<call><{module}{(' ' + rendered) if rendered else ''}/></call>"


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("usage: eamuse_reference_parity.py REFERENCE_DIR PHP_JSON")
    reference = Path(sys.argv[1]).resolve()
    php = json.loads(Path(sys.argv[2]).read_text(encoding="utf-8"))

    sys.path.insert(0, str(reference))
    import server  # type: ignore

    old_db, old_host, old_port = server.DB, server.HOST, server.PORT
    old_sessions = dict(server.PASELI_SESSIONS)
    try:
        with tempfile.TemporaryDirectory() as tmp:
            server.DB = server.ProfileDB(Path(tmp) / "save.json")
            server.HOST = "127.0.0.1"
            server.PORT = 8080
            server.PASELI_SESSIONS.clear()
            os.environ["VFG_CARDMNG_MODE"] = "compat"
            os.environ["VFG_CARDMNG_INQUIRE_MODE"] = "auto"

            def call(module: str, method: str, xml: str = "<call/>") -> str:
                return server.dispatch_eamuse(MODEL, module, method, xml)

            py = {}
            py["services"] = call("services", "get", '<call srcid="PCB-PARITY"/>')
            py["pcbtracker"] = call("pcbtracker", "alive")
            py["message"] = call("message", "get")
            py["facility"] = call("facility", "get")
            py["package"] = call("package", "list")
            py["pcbevent"] = call("pcbevent", "put")
            py["eventlog"] = call("eventlog", "write")
            py["vfgac"] = call("vfgac", "service_list")

            py["card_new"] = call("vfgcard", "inquire", module_xml("vfgcard", cardid=CARD))
            py["card_issue"] = call(
                "vfgcard", "getrefid", module_xml("vfgcard", cardid=CARD, passwd="1234")
            )
            issue_root = ET.fromstring(py["card_issue"])
            issue = issue_root.find(".//vfgcard")
            assert issue is not None
            refid = issue.attrib.get("refid", "")
            assert len(refid) == 16 and issue.attrib.get("dataid") == refid
            py["card_unbound"] = call("vfgcard", "inquire", module_xml("vfgcard", cardid=CARD))
            py["card_auth"] = call(
                "vfgcard", "authpass", module_xml("vfgcard", refid=refid, passwd="1234")
            )
            py["card_bind"] = call("vfgcard", "bindmodel", module_xml("vfgcard", refid=refid))
            py["card_bound"] = call("vfgcard", "inquire", module_xml("vfgcard", cardid=CARD))
            os.environ["VFG_CARDMNG_MODE"] = "strict"
            py["card_malformed_strict"] = call(
                "vfgcard", "inquire", module_xml("vfgcard", cardid="BAD")
            )
            os.environ["VFG_CARDMNG_MODE"] = "compat"

            py["eacoin_checkin"] = call("eacoin", "checkin")
            checkin = ET.fromstring(py["eacoin_checkin"])
            sess_node = checkin.find(".//sessid")
            assert sess_node is not None and (sess_node.text or "")
            sess = (sess_node.text or "").strip()
            py["eacoin_consume"] = call(
                "eacoin", "consume", f"<call><sessid>{sess}</sessid><payment>300</payment></call>"
            )
            py["eacoin_balance"] = call(
                "eacoin", "getbalance", f"<call><sessid>{sess}</sessid></call>"
            )
            py["eacoin_checkout"] = call(
                "eacoin", "checkout", f"<call><sessid>{sess}</sessid></call>"
            )
            py["eacoin_log"] = call("eacoin", "getlog")

            assert set(php) == set(py), (sorted(php), sorted(py))
            for name in py:
                pcanon = canonical(php[name])
                ycanon = canonical(py[name])
                if pcanon != ycanon:
                    raise AssertionError(
                        name
                        + "\nPHP="
                        + json.dumps(pcanon, ensure_ascii=False, indent=2)
                        + "\nPYTHON="
                        + json.dumps(ycanon, ensure_ascii=False, indent=2)
                    )
    finally:
        server.DB, server.HOST, server.PORT = old_db, old_host, old_port
        server.PASELI_SESSIONS.clear()
        server.PASELI_SESSIONS.update(old_sessions)
        os.environ.pop("VFG_CARDMNG_MODE", None)
        os.environ.pop("VFG_CARDMNG_INQUIRE_MODE", None)

    print("e-Amusement PHP/Python reference parity OK")


if __name__ == "__main__":
    main()
