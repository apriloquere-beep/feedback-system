# SFMS Community-Based Health Services Sentiment Dataset

## Purpose

This dataset supports research for **“A Smart Feedback Management System (SFMS) with Sentiment Analysis for Community-Based Health Services.”** It represents text that respondents could enter in the SFMS Comments / Suggestions field.

## Intended use

The intended use is supervised sentiment classification into exactly three classes: `Positive`, `Negative`, and `Neutral`. Confidence is reported separately and does not create an additional sentiment class.

The dataset must not be used for diagnosis, clinical decisions, treatment recommendations, patient-risk prediction, or evaluation of individual healthcare workers.

## Initial release

- Total samples: 900
- Positive: 300
- Negative: 300
- Neutral: 300
- Columns: `record_id`, `text`, `label`, `language_group`, `topic`, `source_group`, `duplicate_group`, `annotator`, `review_status`
- Source: generated/synthetic examples, not real patient records
- Initial review state: Pending

## Language coverage

The text includes English, Bisaya, Tagalog, English–Bisaya, English–Tagalog, Bisaya–English, and other natural mixtures. Tagalog coverage concerns free-text model input only; it does not imply that Tagalog is an SFMS interface language.

## Topic coverage

Topics include staff interaction, healthcare service quality, waiting time and queues, cleanliness and sanitation, facilities, seating and ventilation, accessibility, medicines, medical supplies, pharmacy service, consultation, registration, information and assistance, patient experience, visitor experience, appreciation, complaints, and improvement requests.

## Limitations

All initial examples are synthetic. They may not reproduce the full vocabulary, spelling, demographics, code-switching, ambiguity, or distribution of real community-health feedback. Generated patterns may create artifacts that a classifier can learn instead of genuine sentiment. The dataset must not be presented as collected patient testimony.

This initial dataset is a starting annotation resource, not sufficient evidence of production accuracy. Future versions should incorporate consented, anonymized, independently labeled SFMS feedback and an untouched real-world test set.

## Privacy

The initial release contains no intentional names, contact information, medical record numbers, reference IDs from real submissions, or other patient identifiers. Future real feedback must be de-identified before annotation or training and handled under the project’s approved privacy and research procedures.

## Quality and review

Every initial row is marked `Pending`. Human reviewers must verify sentiment, naturalness, language grouping, topic, duplicates, and privacy before model training. Dataset splits must keep duplicate or paraphrase groups together and must be regenerated reproducibly from the reviewed master file.
