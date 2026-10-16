from datetime import datetime
from emailai.style import learn_style
from emailai.vectors import VectorStore
from emailai.calendar import parse_ics
from emailai.reply import draft_reply, calendar_answer

SENT = ["Hi Anna,\n\nSounds good, thanks!\n\nBest regards,\nBob\nCTO, Acme",
        "Hi Tom,\n\nLet's talk tomorrow.\n\nBest regards,\nBob\nCTO, Acme"]
ICS = ("BEGIN:VEVENT\nDTSTART:20261020T100000\nDTEND:20261020T110000\nSUMMARY:Call TBD\n"
       "END:VEVENT\nBEGIN:VEVENT\nDTSTART:20261021T100000\nDTEND:20261021T110000\n"
       "SUMMARY:Review\nSTATUS:CONFIRMED\nEND:VEVENT")


def test_style():
    p = learn_style(SENT)
    assert p["signature"] == "Bob\nCTO, Acme" and p["closing"] == "Best regards,"
    assert p["greeting"] == "Hi {name},"


def test_vectors(tmp_path):
    s = VectorStore(str(tmp_path / "v.json"))
    s.add("meeting tomorrow"); s.add("invoice payment")
    s.save()
    assert VectorStore(str(tmp_path / "v.json")).search("meeting")[0]["text"] == "meeting tomorrow"


def test_calendar_and_reply():
    ev = parse_ics(ICS)
    assert "Confirmed" in calendar_answer(ev, datetime(2026, 10, 21, 10))
    assert "instead" in calendar_answer(ev, datetime(2026, 10, 20, 10))
    r = draft_reply(learn_style(SENT), "Carl", "", ev, datetime(2026, 10, 21, 10))
    assert r.startswith("Hi Carl,") and r.endswith("Bob\nCTO, Acme")
