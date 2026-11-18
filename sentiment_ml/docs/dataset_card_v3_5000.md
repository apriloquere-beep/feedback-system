# SFMS Multilingual Healthcare Sentiment Dataset v3

## Status and purpose

This is a separate, developer-generated synthetic release for research prototyping and human annotation. It is not real patient feedback and must not be used for clinical decisions, evaluation of individual staff, or claims about real-world model performance.

All records are marked `source_group=generated_synthetic_v3` and `review_status=Pending`. The examples were generated from language-specific templates and phrase banks; they were not collected from patients, downloaded from an external website, or translated from an external corpus. Records in different languages are not verified translation pairs.

## Contents

- Total: 5,000 samples
- English: 1,667
- Filipino/Tagalog: 1,667
- Bisaya/Cebuano: 1,666
- Sentiment: Positive 1,667; Negative 1,667; Neutral 1,666
- Fields: `record_id`, `text`, `label`, `language_group`, `topic`, `source_group`, `duplicate_group`, `annotator`, `review_status`

### Language and sentiment counts

| Language | Positive | Negative | Neutral | Total |
|---|---:|---:|---:|---:|
| English | 556 | 556 | 555 | 1,667 |
| Filipino/Tagalog | 556 | 555 | 556 | 1,667 |
| Bisaya/Cebuano | 555 | 556 | 555 | 1,666 |
| **Total** | **1,667** | **1,667** | **1,666** | **5,000** |

## Split files

Strata are allocated approximately proportionally while meeting the exact requested split sizes. Semantic generation families are assigned to a single split before records are assembled.

- Training: 3,500 (70%)
- Validation: 750 (15%)
- Testing: 750 (15%)

Files are in `data/splits/v3_5000/`. Keep these splits fixed for evaluation and do not tune a model against the test split.

## Generation, validation, and limitations

The fixed-seed generator combines template frames, language-specific actions, healthcare topics, and context phrases. Each generated row contains two distinct generated event sentences. The records cover consultations, nursing, queues, triage, laboratory services, medical documents, counseling, pharmacy, registration, facilities, accessibility, vaccination outreach, and care coordination.

Automated validation confirmed 5,000 rows, exact language and sentiment counts, exact split totals, unique record IDs, no missing required fields, no normalized exact-text duplicates, no exact text overlap with the original 900-row master, no repeated event sentences, no semantic families shared across splits, and no pairs at or above 0.88 SequenceMatcher similarity among records sharing a semantic family.

These checks do not establish that each sentiment label is correct, that every sentence is idiomatic, or that examples are equivalent across languages. Template composition can sound repetitive or awkward, and automated checks cannot replace semantic review. Filipino/Tagalog and Cebuano wording, sentiment nuance, topic fit, and labels require review by fluent speakers familiar with healthcare feedback before the data is used for model training or research claims. All records must remain pending until reviewed.

## Relationship to the original dataset

The original 900-row master at `data/labeled/sfms_sentiment_master.csv` was preserved unchanged. Its SHA-256 before and after generation was `512d998a69f9136dbe6732c62704f41a0477d0a5d1b8d26c67694586675669d8`.

The production sentiment analyzer and existing model artifacts were not modified, and no model was trained or deployed as part of this release.
