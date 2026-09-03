#!/usr/bin/env python3
"""Cross-check PHP KBinXml against the kbinxml package used by the Python server."""

from __future__ import annotations

import sys
from pathlib import Path
from xml.etree import ElementTree as ET

from kbinxml import KBinXML


FIXTURE = (
    '<?xml version="1.0" encoding="UTF-8"?>'
    '<call model="VFG:J:A:A:2025122300" srcid="00010203040506070809">'
    '<interop method="roundtrip" area="東京都">'
    '<title __type="str">麻雀ファイトガール</title>'
    '<small __type="u8">7</small>'
    '<signed __type="s64">-9223372036854775808</signed>'
    '<unsigned __type="u64">18446744073709551615</unsigned>'
    '<pair __type="2s64">-9007199254740993 9007199254740993</pair>'
    '<globalip __type="ip4">127.0.0.1</globalip>'
    '</interop></call>'
)


def check_xml(text: str) -> None:
    root = ET.fromstring(text)
    assert root.tag == "call"
    assert root.attrib.get("model") == "VFG:J:A:A:2025122300"
    node = root.find("interop")
    assert node is not None
    assert node.attrib.get("method") == "roundtrip"
    assert node.attrib.get("area") == "東京都"
    assert node.findtext("title") == "麻雀ファイトガール"
    assert node.findtext("small") == "7"
    assert node.findtext("signed") == "-9223372036854775808"
    assert node.findtext("unsigned") == "18446744073709551615"
    assert node.findtext("pair") == "-9007199254740993 9007199254740993"
    assert node.findtext("globalip") == "127.0.0.1"


def main() -> None:
    if len(sys.argv) != 3:
        raise SystemExit("usage: kbin_reference_interop.py PHP_BIN PYTHON_BIN")

    php_path = Path(sys.argv[1])
    python_path = Path(sys.argv[2])

    # PHP -> official/reference Python kbinxml decoder.
    php_bin = php_path.read_bytes()
    assert KBinXML.is_binary_xml(php_bin)
    decoded = KBinXML(php_bin, convert_illegal_things=True)
    assert str(getattr(decoded, "encoding", "")).upper().replace("_", "-") in {"UTF-8", "UTF8"}
    assert bool(getattr(decoded, "compressed", False)) is True
    check_xml(decoded.to_text())

    # Python reference encoder -> PHP decoder.
    encoded = KBinXML(FIXTURE.encode("utf-8")).to_binary(
        encoding="UTF-8", compressed=True
    )
    assert KBinXML.is_binary_xml(encoded)
    python_path.write_bytes(encoded)

    print("PHP <-> Python kbinxml interoperability OK")


if __name__ == "__main__":
    main()
