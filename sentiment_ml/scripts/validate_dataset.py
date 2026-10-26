from __future__ import annotations

import csv
import sys
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DATASET = ROOT / "data" / "labeled" / "sfms_sentiment_master.csv"
REQUIRED_COLUMNS = [
    "record_id", "text", "label", "language_group", "topic",
    "source_group", "duplicate_group", "annotator", "review_status",
]
VALID_LABELS = {"Positive", "Negative", "Neutral"}
VALID_LANGUAGES = {
    "English", "Bisaya", "Tagalog", "English-Bisaya", "English-Tagalog",
    "Bisaya-English", "Other-Mixed",
}
VALID_REVIEW_STATUSES = {"Pending", "Reviewed", "Adjudicated"}


def normalized(text: str) -> str:
    return " ".join(text.casefold().split())


def main() -> int:
    errors: list[str] = []
    warnings: list[str] = []
    print("SFMS sentiment dataset validation")
    print(f"Dataset: {DATASET}")

    if not DATASET.exists():
        print("ERROR: dataset file does not exist")
        return 1

    try:
        with DATASET.open("r", encoding="utf-8-sig", newline="") as handle:
            reader = csv.DictReader(handle)
            columns = reader.fieldnames or []
            rows = list(reader)
    except UnicodeDecodeError as exc:
        print(f"ERROR: dataset is not valid UTF-8: {exc}")
        return 1

    missing = [column for column in REQUIRED_COLUMNS if column not in columns]
    extra = [column for column in columns if column not in REQUIRED_COLUMNS]
    if missing:
        errors.append(f"missing required columns: {', '.join(missing)}")
    if extra:
        warnings.append(f"unexpected columns: {', '.join(extra)}")
    if len(rows) != 900:
        errors.append(f"expected 900 rows; found {len(rows)}")

    labels = Counter(row.get("label", "") for row in rows)
    languages = Counter(row.get("language_group", "") for row in rows)
    topics = Counter(row.get("topic", "") for row in rows)
    review_statuses = Counter(row.get("review_status", "") for row in rows)
    texts = [normalized(row.get("text", "")) for row in rows]
    duplicates = len(texts) - len(set(texts))

    for label in VALID_LABELS:
        if labels[label] != 300:
            errors.append(f"expected 300 {label} rows; found {labels[label]}")
    invalid_labels = sorted(set(labels) - VALID_LABELS)
    if invalid_labels:
        errors.append(f"invalid labels: {', '.join(repr(x) for x in invalid_labels)}")
    if labels["Mixed"] or labels["Not Sure"]:
        errors.append("Mixed and Not Sure must not be training classes")

    empty_text = sum(not text for text in texts)
    if empty_text:
        errors.append(f"empty text rows: {empty_text}")
    if duplicates:
        errors.append(f"exact normalized duplicate texts: {duplicates}")

    invalid_languages = sorted(set(languages) - VALID_LANGUAGES)
    if invalid_languages:
        errors.append(f"invalid language_group values: {', '.join(repr(x) for x in invalid_languages)}")
    invalid_statuses = sorted(set(review_statuses) - VALID_REVIEW_STATUSES)
    if invalid_statuses:
        errors.append(f"invalid review_status values: {', '.join(repr(x) for x in invalid_statuses)}")

    record_ids = [row.get("record_id", "") for row in rows]
    duplicate_groups = [row.get("duplicate_group", "") for row in rows]
    if len(record_ids) != len(set(record_ids)):
        errors.append("record_id values are not unique")
    if any(not value for value in duplicate_groups):
        errors.append("one or more duplicate_group values are empty")

    short = sum(0 < len(text) < 12 for text in texts)
    long = sum(len(text) > 500 for text in texts)
    if short:
        warnings.append(f"very short texts under 12 characters: {short}")
    if long:
        warnings.append(f"texts over 500 characters: {long}")

    print(f"Total rows: {len(rows)}")
    print("Label counts:", dict(sorted(labels.items())))
    print("Language counts:", dict(sorted(languages.items())))
    print("Topic counts:", dict(sorted(topics.items())))
    print("Review statuses:", dict(sorted(review_statuses.items())))
    print(f"Exact duplicate count: {duplicates}")
    print(f"Warnings: {len(warnings)}")
    for warning in warnings:
        print(f"  WARNING: {warning}")
    print(f"Errors: {len(errors)}")
    for error in errors:
        print(f"  ERROR: {error}")
    print("VALIDATION PASSED" if not errors else "VALIDATION FAILED")
    return 0 if not errors else 1


if __name__ == "__main__":
    sys.exit(main())
