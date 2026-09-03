#!/usr/bin/env python3
"""Cross-check PHP RC4/LZ77/KBin wire format against reference protocol.py."""

from __future__ import annotations

import sys
from pathlib import Path
from xml.etree import ElementTree as ET

INFO = "1-01234567-89ab"
FIXTURE = (
    '<?xml version="1.0" encoding="UTF-8"?>'
    '<call model="VFG:J:A:A:2025122300">'
    '<eventlog method="write">'
    '<gamesession __type="s64">9223372036854775807</gamesession>'
    '<message __type="str">麻雀ファイトガール</message>'
    '<globalip __type="ip4">127.0.0.1</globalip>'
    '</eventlog></call>'
)


def assert_xml(text: str) -> None:
    root = ET.fromstring(text)
    assert root.tag == "call"
    assert root.attrib.get("model") == "VFG:J:A:A:2025122300"
    eventlog = root.find("eventlog")
    assert eventlog is not None and eventlog.attrib.get("method") == "write"
    assert eventlog.findtext("gamesession") == "9223372036854775807"
    assert eventlog.findtext("message") == "麻雀ファイトガール"
    assert eventlog.findtext("globalip") == "127.0.0.1"


def main() -> None:
    if len(sys.argv) != 4:
        raise SystemExit(
            "usage: transport_reference_interop.py REFERENCE_DIR PHP_WIRE PYTHON_WIRE"
        )
    reference = Path(sys.argv[1]).resolve()
    sys.path.insert(0, str(reference))
    import protocol  # type: ignore  # reference repository module

    php_wire = Path(sys.argv[2]).read_bytes()
    text, meta = protocol.decode_eamuse_body(php_wire, INFO, "lz77")
    assert meta.used_kbin
    assert str(meta.kbin_encoding).upper().replace("_", "-") in {"UTF-8", "UTF8"}
    assert bool(meta.kbin_compressed) is True
    assert_xml(text)

    python_wire = protocol.encode_eamuse_body(
        FIXTURE,
        INFO,
        "lz77",
        protocol.EamuseDecodeMeta(
            used_kbin=True,
            kbin_encoding="UTF-8",
            kbin_compressed=True,
        ),
    )
    Path(sys.argv[3]).write_bytes(python_wire)
    print("PHP <-> Python reference RC4/LZ77/KBin transport OK")


if __name__ == "__main__":
    main()
