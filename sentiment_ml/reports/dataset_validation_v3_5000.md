# SFMS multilingual sentiment dataset v3 validation

## Result

- Status: **PASS**
- Total: **5,000** synthetic feedback samples
- Provenance: every record is marked `generated_synthetic_v3`; none is represented as real patient feedback.
- Human review: every record is `Pending`; this report does not claim native-speaker or clinician review.

## Distributions

| Language | Samples | Positive | Negative | Neutral |
|---|---:|---:|---:|---:|
| English | 1667 | 556 | 556 | 555 |
| Filipino/Tagalog | 1667 | 556 | 555 | 556 |
| Bisaya/Cebuano | 1666 | 555 | 556 | 555 |

| Split | Samples |
|---|---:|
| Train | 3500 |
| Validation | 750 |
| Test | 750 |

## Validation checks

- Unique record IDs: **PASS**.
- Exact normalized text duplicates: **0**.
- Exact overlap with the preserved original 900-row master: **0**.
- Semantic families shared across splits: **0**.
- Reused event sentences: **0**.
- Repeated semantic family pairs: **0**.
- Near-duplicate screen: 14075 complete-record pairs sharing a semantic family checked; 0 at or above 0.88 SequenceMatcher similarity.

The split builder assigns entire semantic families to a single split before pairing distinct, unused event sentences. This prevents a generated action/detail family from appearing across train, validation, and test. The lexical near-duplicate screen is not a substitute for human semantic review.

## Output files

- `data\labeled\sfms_sentiment_v3_5000.csv` — SHA-256 `61986a59dd68f44b166c892ae2514fc3ee225dab7eb0dc5eed1382438c93b0dd`
- `data\splits\v3_5000\train.csv` — SHA-256 `908d2013dfc8ae82fb8e64b8f3fc2e3ba8c5c5c3ae126acba281e5935b7c6981`
- `data\splits\v3_5000\validation.csv` — SHA-256 `17815e4118adca06fd016a67b1381308c5c6821d1325416591908d5ed4ad1ef0`
- `data\splits\v3_5000\test.csv` — SHA-256 `9ef7c352827c380b626f5519b209329a33a530a81f53b9361efa91de6d125377`

## Limitations

All records are controlled synthetic compositions based on language-specific, sentiment-specific phrase banks. They are not downloaded, translated from an external corpus, or collected from actual patients. Template structure and phrase reuse may make the corpus less linguistically diverse than naturally occurring feedback. Tagalog and Cebuano wording, sentiment nuance, and topic fit require independent native-speaker review before model training or research claims.
