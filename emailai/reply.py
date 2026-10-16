"""Train on the mailbox, then draft a reply that mimics the user's style."""
import email, imaplib, json
from datetime import timedelta
from email import policy

from .calendar import is_confirmed, propose_slot
from .style import learn_style
from .vectors import VectorStore


def _body(msg):
    part = msg.get_body(preferencelist=("plain",))
    return part.get_content() if part else ""


def fetch_sent(host, user, password, folder='"[Gmail]/Sent Mail"', limit=100):
    """Fetch bodies of sent emails, newest first."""
    with imaplib.IMAP4_SSL(host) as imap:
        imap.login(user, password)
        imap.select(folder, readonly=True)
        _, data = imap.search(None, "ALL")
        ids = data[0].split()[-limit:][::-1]
        out = []
        for i in ids:
            _, d = imap.fetch(i, "(RFC822)")
            out.append(_body(email.message_from_bytes(d[0][1], policy=policy.default)))
        return out


def train(sent_bodies, profile_path="style_profile.json", vectors_path="style_vectors.json"):
    profile = learn_style(sent_bodies)
    store = VectorStore(vectors_path)
    for b in sent_bodies:
        if b.strip():
            store.add(b.strip())
    store.save()
    with open(profile_path, "w") as f:
        json.dump(profile, f, indent=2)
    return profile


def calendar_answer(events, start, duration=timedelta(hours=1)):
    status, ev = is_confirmed(events, start, start + duration)
    if status == "confirmed":
        return f"Confirmed: the appointment on {start:%A %d %B at %H:%M} is confirmed."
    if status in ("tbd", "busy", "free"):
        if status == "free":
            return f"{start:%A %d %B at %H:%M} works for me, I look forward to it."
        alt = propose_slot(events, start + timedelta(hours=1), duration)
        if alt:
            return (f"Unfortunately {start:%A %d %B at %H:%M} is not confirmed yet. "
                    f"Could we meet on {alt:%A %d %B at %H:%M} instead?")
    return "Unfortunately that time is not available; could you suggest another slot?"


def draft_reply(profile, sender_name, body_text, events=None, requested_start=None, llm=None):
    """llm: optional callable(profile, body_text) -> reply text; otherwise a template is used."""
    parts = []
    if requested_start is not None and events is not None:
        parts.append(calendar_answer(events, requested_start))
    if llm:
        parts.insert(0, llm(profile, body_text))
    elif not parts:
        parts.append("Thank you for your message. I will get back to you shortly.")
    greeting = profile["greeting"].replace("{name}", sender_name)
    msg = f"{greeting}\n\n" + "\n\n".join(parts) + f"\n\n{profile['closing']}"
    if profile["signature"]:
        msg += f"\n{profile['signature']}"
    return msg
