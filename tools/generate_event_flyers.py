from __future__ import annotations

import html
import json
import re
import sys
import urllib.parse
import urllib.request
from datetime import datetime
from io import BytesIO
from pathlib import Path

from PIL import Image, ImageOps
from reportlab.graphics import renderPDF
from reportlab.graphics.barcode import qr
from reportlab.graphics.shapes import Drawing
from reportlab.lib.colors import HexColor, white
from reportlab.lib.pagesizes import letter
from reportlab.lib.utils import ImageReader
from reportlab.pdfgen import canvas


SITE = "https://www.mashouse.studio"
EVENTS_API = SITE + "/wp-json/tribe/events/v1/events"
DRIVE_EVENT_ROOT = Path.home() / "My Drive" / "Documents" / "Ma's House" / "Events at Ma's House"
OUTPUT_ROOT = Path(__file__).resolve().parents[1] / ".generated" / "event-flyers"

RED = HexColor("#ED1C24")
BLUE = HexColor("#0A2A7A")
INK = HexColor("#101010")
MUTED = HexColor("#555555")
PALE = HexColor("#F4F1EA")


def clean_text(value: str) -> str:
    value = html.unescape(value or "")
    value = re.sub(r"<[^>]+>", " ", value)
    value = value.replace("\u2019", "'").replace("\u2013", "-").replace("\u2014", "-")
    return re.sub(r"\s+", " ", value).strip()


def slugify(value: str, limit: int = 70) -> str:
    value = clean_text(value).replace("&", "and")
    value = re.sub(r"[^A-Za-z0-9]+", "-", value).strip("-").lower()
    return value[:limit].strip("-") or "event"


def request_json(url: str) -> dict:
    request = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
    with urllib.request.urlopen(request, timeout=45) as response:
        return json.loads(response.read().decode("utf-8"))


def upcoming_events(limit: int = 30) -> list[dict]:
    query = urllib.parse.urlencode({"per_page": limit, "start_date": "now"})
    payload = request_json(f"{EVENTS_API}?{query}")
    return payload.get("events", [])


def parse_event_datetime(value: str) -> datetime:
    value = (value or "").replace("T", " ")[:19]
    return datetime.strptime(value, "%Y-%m-%d %H:%M:%S")


def format_datetime_range(start: datetime, end: datetime) -> str:
    if start.date() != end.date():
        return f"{start.strftime('%B %d').replace(' 0', ' ')} - {end.strftime('%B %d, %Y').replace(' 0', ' ')}"
    start_time = start.strftime("%I:%M %p").lstrip("0").replace(":00", "").lower()
    end_time = end.strftime("%I:%M %p").lstrip("0").replace(":00", "").lower()
    return f"{start.strftime('%B %d, %Y').replace(' 0', ' ')} | {start_time} - {end_time}"


def event_image_url(event: dict) -> str:
    image = event.get("image") or {}
    if isinstance(image, dict):
        for key in ("sizes", "url"):
            value = image.get(key)
            if isinstance(value, dict):
                for size in ("full", "large", "medium_large", "medium"):
                    size_value = value.get(size)
                    if isinstance(size_value, dict) and size_value.get("url"):
                        return size_value["url"]
            elif isinstance(value, str):
                return value
    return ""


def event_url(event: dict) -> str:
    website = clean_text(event.get("website") or "")
    if website:
        return website.replace("#new_tab", "")
    return (event.get("url") or event.get("link") or "").replace("#new_tab", "")


def event_venue(event: dict) -> str:
    venue = event.get("venue") or {}
    if isinstance(venue, dict):
        return clean_text(venue.get("venue") or venue.get("title") or "")
    return clean_text(str(venue))


def event_address(event: dict) -> str:
    venue = event.get("venue") or {}
    if not isinstance(venue, dict):
        return ""
    parts = [
        venue.get("address"),
        venue.get("city"),
        venue.get("stateprovince"),
        venue.get("zip"),
    ]
    return clean_text(", ".join(str(part) for part in parts if part))


def fetch_image(url: str, cache_path: Path) -> Path | None:
    if not url:
        return None
    if cache_path.exists():
        return cache_path
    try:
        request = urllib.request.Request(url, headers={"User-Agent": "Mozilla/5.0"})
        with urllib.request.urlopen(request, timeout=45) as response:
            data = response.read()
        image = Image.open(BytesIO(data)).convert("RGB")
        image.thumbnail((1800, 1800))
        cache_path.parent.mkdir(parents=True, exist_ok=True)
        image.save(cache_path, quality=92)
        return cache_path
    except Exception as exc:
        print(f"Image skipped for {url}: {exc}", file=sys.stderr)
        return None


def fit_image(path: Path, width: float, height: float) -> Image.Image:
    image = Image.open(path).convert("RGB")
    image = ImageOps.exif_transpose(image)
    return ImageOps.fit(
        image,
        (int(width * 2), int(height * 2)),
        method=Image.Resampling.LANCZOS,
        centering=(0.5, 0.5),
    )


def wrap_lines(pdf: canvas.Canvas, text: str, font: str, size: int, max_width: float) -> list[str]:
    words = clean_text(text).split()
    lines: list[str] = []
    line = ""
    for word in words:
        test = f"{line} {word}".strip()
        if not line or pdf.stringWidth(test, font, size) <= max_width:
            line = test
        else:
            lines.append(line)
            line = word
    if line:
        lines.append(line)
    return lines


def draw_wrapped(
    pdf: canvas.Canvas,
    text: str,
    x: float,
    y: float,
    max_width: float,
    font: str,
    size: int,
    leading: int,
    max_lines: int | None,
    color,
) -> float:
    pdf.setFillColor(color)
    pdf.setFont(font, size)
    lines = wrap_lines(pdf, text, font, size, max_width)
    if max_lines and len(lines) > max_lines:
        lines = lines[:max_lines]
        lines[-1] = re.sub(r"[\s,.!?;:]+$", "", lines[-1]) + "..."
    for line in lines:
        pdf.drawString(x, y, line)
        y -= leading
    return y


def draw_qr(pdf: canvas.Canvas, url: str, x: float, y: float, size: float) -> None:
    widget = qr.QrCodeWidget(url)
    bounds = widget.getBounds()
    drawing = Drawing(
        size,
        size,
        transform=[size / (bounds[2] - bounds[0]), 0, 0, size / (bounds[3] - bounds[1]), 0, 0],
    )
    drawing.add(widget)
    renderPDF.draw(drawing, pdf, x, y)


def render_flyer(event: dict) -> Path:
    start = parse_event_datetime(event.get("start_date") or event.get("start_date_details", {}).get("date", ""))
    end = parse_event_datetime(event.get("end_date") or event.get("end_date_details", {}).get("date", ""))
    title = clean_text(event.get("title") or "")
    url = event_url(event)
    event_id = event.get("id") or slugify(title)
    slug = f"{start:%Y-%m-%d}-{slugify(title)}"
    pdf_path = OUTPUT_ROOT / f"{slug}-flyer.pdf"
    image_path = fetch_image(event_image_url(event), OUTPUT_ROOT / "images" / f"{event_id}.jpg")

    width, height = letter
    pdf_path.parent.mkdir(parents=True, exist_ok=True)
    pdf = canvas.Canvas(str(pdf_path), pagesize=letter)
    pdf.setFillColor(PALE)
    pdf.rect(0, 0, width, height, fill=1, stroke=0)
    pdf.setFillColor(BLUE)
    pdf.rect(0, height - 98, width, 98, fill=1, stroke=0)
    pdf.setFillColor(RED)
    pdf.rect(0, height - 108, width, 10, fill=1, stroke=0)
    pdf.setFillColor(white)
    pdf.setFont("Helvetica-Bold", 13)
    pdf.drawString(46, height - 48, "MA'S HOUSE & BIPOC ART STUDIO")
    pdf.setFont("Helvetica", 10)
    pdf.drawString(46, height - 67, "Public Program")

    if image_path:
        hero = fit_image(image_path, width - 92, 280)
        pdf.drawImage(ImageReader(hero), 46, height - 410, width - 92, 280, mask="auto")

    y = height - 455
    pdf.setFillColor(RED)
    pdf.setFont("Helvetica-Bold", 12)
    pdf.drawString(46, y, format_datetime_range(start, end).upper())
    y -= 34
    y = draw_wrapped(pdf, title, 46, y, width - 92, "Helvetica-Bold", 26, 29, 5, INK) - 8
    venue = event_venue(event)
    if venue:
        pdf.setFillColor(INK)
        pdf.setFont("Helvetica-Bold", 12)
        pdf.drawString(46, y, venue)
        y -= 18
    y = draw_wrapped(pdf, event_address(event), 46, y, width - 230, "Helvetica", 11, 14, 2, MUTED) - 12
    excerpt = clean_text(event.get("excerpt") or event.get("description") or "")
    draw_wrapped(pdf, excerpt, 46, y, width - 230, "Helvetica", 12, 16, 5, INK)

    pdf.setFillColor(BLUE)
    pdf.rect(0, 0, width, 104, fill=1, stroke=0)
    if url:
        draw_qr(pdf, url, 46, 20, 64)
    pdf.setFillColor(white)
    pdf.setFont("Helvetica-Bold", 13)
    pdf.drawString(128, 62, "Scan for event details and RSVP")
    pdf.setFont("Helvetica", 9)
    pdf.drawString(128, 43, url.replace("https://", "")[:80])
    pdf.setFont("Helvetica", 9)
    pdf.drawRightString(width - 46, 24, "mashouse.studio")
    pdf.save()
    return pdf_path


def choose_event_folder(event: dict) -> Path:
    start = parse_event_datetime(event.get("start_date") or event.get("start_date_details", {}).get("date", ""))
    title = clean_text(event.get("title") or "")
    year_root = DRIVE_EVENT_ROOT / f"{start:%Y}"
    year_root.mkdir(parents=True, exist_ok=True)

    date_prefix = f"{start:%Y-%m-%d}"
    title_words = [word.lower() for word in re.findall(r"[A-Za-z0-9]+", title) if len(word) > 3]
    candidates = [p for p in year_root.iterdir() if p.is_dir() and p.name.startswith(date_prefix)]
    scored: list[tuple[int, Path]] = []
    for folder in year_root.iterdir():
        if not folder.is_dir():
            continue
        name = folder.name.lower()
        score = sum(1 for word in title_words if word in name)
        if score:
            scored.append((score, folder))
    if scored:
        scored = sorted(scored, reverse=True)
        if scored[0][0] >= 2:
            return scored[0][1]
    if len(candidates) == 1:
        return candidates[0]
    if candidates:
        scored_candidates = sorted(
            ((sum(1 for word in title_words if word in folder.name.lower()), folder) for folder in candidates),
            reverse=True,
        )
        if scored_candidates and scored_candidates[0][0] >= 1:
            return scored_candidates[0][1]

    folder_name = f"{date_prefix} {re.sub(r'[^A-Za-z0-9 ]+', '', title)[:58].strip()}"
    folder = year_root / folder_name
    folder.mkdir(parents=True, exist_ok=True)
    return folder


def flyer_destination_name(event: dict) -> str:
    start = parse_event_datetime(event.get("start_date") or event.get("start_date_details", {}).get("date", ""))
    title = clean_text(event.get("title") or "")
    useful_words = [
        word
        for word in re.findall(r"[A-Za-z0-9]+", title)
        if word.lower() not in {"partnership", "program", "workshop", "event", "house", "reserve"}
    ]
    short_title = " ".join(useful_words[:8]) or "Event"
    return f"Flyer - {start:%Y-%m-%d} {short_title}.pdf"


def main() -> int:
    events = upcoming_events()
    copied = []
    for event in events:
        pdf_path = render_flyer(event)
        folder = choose_event_folder(event)
        start = parse_event_datetime(event.get("start_date") or event.get("start_date_details", {}).get("date", ""))
        existing = sorted(folder.glob(f"Flyer - {start:%Y-%m-%d}*.pdf"))
        destination = existing[0] if existing else folder / flyer_destination_name(event)
        destination.write_bytes(pdf_path.read_bytes())
        copied.append(str(destination))
    print(json.dumps({"created": copied}, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
