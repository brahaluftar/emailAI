"""Check an .ics calendar for TBD/tentative events and conflicts."""
import re
from datetime import datetime, timedelta


def _unfold(text):
    return re.sub(r"\r?\n[ \t]", "", text)


def _dt(val):
    val = val.strip().rstrip("Z")
    return datetime.strptime(val, "%Y%m%dT%H%M%S" if "T" in val else "%Y%m%d")


def parse_ics(text):
    events = []
    for block in re.findall(r"BEGIN:VEVENT(.*?)END:VEVENT", _unfold(text), re.S):
        ev = {}
        for line in block.strip().splitlines():
            if ":" not in line:
                continue
            key, val = line.split(":", 1)
            ev[key.split(";")[0].upper()] = val
        if "DTSTART" in ev:
            start = _dt(ev["DTSTART"])
            end = _dt(ev["DTEND"]) if "DTEND" in ev else start + timedelta(hours=1)
            events.append({"summary": ev.get("SUMMARY", ""), "start": start, "end": end,
                           "status": ev.get("STATUS", "CONFIRMED").upper()})
    return events


def is_confirmed(events, start, end, tbd_marker="TBD"):
    """Return (status, conflicting_event). status: confirmed | tbd | free | busy."""
    for e in events:
        if e["start"] < end and start < e["end"]:
            if e["status"] == "TENTATIVE" or tbd_marker.lower() in e["summary"].lower():
                return "tbd", e
            return ("confirmed" if e["start"] == start else "busy"), e
    return "free", None


def propose_slot(events, start, duration=timedelta(hours=1), step=timedelta(hours=1), tries=48):
    t = start
    for _ in range(tries):
        if 9 <= t.hour < 18 and t.weekday() < 5 and is_confirmed(events, t, t + duration)[0] == "free":
            return t
        t += step
    return None
