# TOEIC Learning Platform — Specification

This document specifies the functional behavior and technical design of the
TOEIC Learning Platform. For setup and operation, see [README.md](./README.md).

---

## 1. Overview

### 1.1 Purpose
Help a learner build and retain TOEIC-relevant English vocabulary and grammar
through three reinforcing loops:

1. **Acquire** — look up real words; AI enriches them with Chinese meaning and
   TOEIC usage notes.
2. **Retain** — an SM-2 spaced-repetition scheduler resurfaces words just before
   they're forgotten.
3. **Assess & adapt** — AI-generated quizzes target the learner's weak words and
   weak grammar points; results feed back into both the scheduler and the
   adaptive question selector.

### 1.2 Scope & assumptions
- **Multi-user.** Session-cookie authentication; every user has a private
  training database. Personal data (`user_words`, `quizzes`, `review_logs`) is
  partitioned by `user_id`; the `words` dictionary/seed cache is shared global
  reference data. Open self-service registration. An admin role (`is_admin`)
  unlocks user management. Accounts can be disabled (`is_active=false`).
- **Traditional Chinese** is the translation/explanation language throughout AI
  prompts.
- AI features are **optional**: with no `ANTHROPIC_API_KEY`, dictionary lookups
  still function and AI features are disabled with a visible banner.

### 1.3 Non-goals
- Listening/reading full-test simulation (Parts 1–4, 6, 7).
- Sharing or social features; cross-user data sharing.
- Email verification / password-reset-by-email flows (admins reset passwords).
- Mobile native apps (the web UI is responsive instead).

---

## 2. Domain Model

### 2.1 Entities

#### `users` — accounts
Laravel's default users table plus two role flags.

| Column        | Type        | Notes                                              |
|---------------|-------------|----------------------------------------------------|
| `name`        | string      | **unique** — the login id                          |
| `email`       | string      | nullable, optional (unique when present)           |
| `password`    | string      | hashed                                             |
| `is_admin`    | boolean     | default false; unlocks the admin area              |
| `is_active`   | boolean     | default true; false ⇒ cannot log in                |

Owns `user_words`, `quizzes`, `review_logs` (each `cascadeOnDelete`).

#### `words` — dictionary cache / word catalog
Canonical, deduplicated record of a word's reference data. Populated from the
seed list, the dictionary API, or AI-generated new words.

| Column          | Type        | Notes                                              |
|-----------------|-------------|----------------------------------------------------|
| `word`          | string(100) | **unique**, lowercased                             |
| `phonetic`      | string      | IPA, nullable                                      |
| `audio_url`     | string      | pronunciation audio, nullable                      |
| `part_of_speech`| string(20)  | primary POS (mirrors first meaning)                |
| `definition_en` | text        | primary English definition                         |
| `definition_zh` | text        | primary Chinese translation                        |
| `meanings`      | json        | `[{pos, definition_en, definition_zh, example}]` per POS |
| `example`       | text        | primary example sentence                           |
| `toeic_note`    | text        | AI TOEIC usage tip (Chinese)                       |
| `mnemonic`      | text        | AI memory aid / mnemonic (Chinese), nullable       |
| `synonyms`      | string      | comma-joined, ≤6                                   |
| `level`         | tinyint     | difficulty 1–5, default 3                          |
| `category`      | string(50)  | business/finance/office/travel/general/…           |
| `source`        | string(10)  | `seed` \| `api`                                    |
| `raw_json`      | json        | raw dictionary API entry                           |

#### `user_words` — personal vocabulary + SM-2 state
| Column            | Type         | Notes                                                       |
|-------------------|--------------|-------------------------------------------------------------|
| `user_id`         | FK→users     | owner, `cascadeOnDelete`                                    |
| `word_id`         | FK→words     | nullable, `nullOnDelete`                                    |
| `word`            | string(100)  | lowercased; **unique per user** (`user_id, word`)          |
| `source`          | string(15)   | `search`\|`manual`\|`quiz_weak`\|`quiz_new`\|`ai_review`    |
| `notes`           | text         | user notes                                                  |
| `tags`            | string(200)  | free-text, comma-style; matched by `LIKE`                  |
| `ease_factor`     | decimal(4,2) | SM-2 EF, default 2.5, floor 1.3                            |
| `interval_days`   | int          | current interval, default 0                                |
| `repetitions`     | int          | consecutive successful reviews                             |
| `lapses`          | int          | cumulative failures (≥4 ⇒ leech)                           |
| `next_review_at`  | timestamp    | due date                                                    |
| `last_reviewed_at`| timestamp    | last graded                                                 |

#### `quizzes`
| Column        | Type        | Notes                                          |
|---------------|-------------|------------------------------------------------|
| `user_id`     | FK→users    | owner, `cascadeOnDelete`                        |
| `title`       | string(200) | e.g. "Part 5 Grammar Quiz · 06/04 14:30"       |
| `type`        | string(20)  | `vocab_mc`\|`part5_grammar`\|`fill_blank`\|`news_reading` |
| `scope`       | string(10)  | `builtin`\|`custom`\|`mixed`\|`news`            |
| `status`      | string(20)  | `pending`\|`completed`                          |
| `score`       | tinyint     | correct count, nullable                         |
| `total`       | tinyint     | question count                                  |
| `ai_review`   | json        | `{summary, weaknesses, review_words[], review_grammar[]}` |
| `article`     | json        | news quizzes only: `{source, topic, title, url, published_at, passage, level, suitable, suitability_note, summary, vocab[]}` |
| `completed_at`| timestamp   | set on submit                                   |

#### `quiz_questions`
| Column          | Type        | Notes                                  |
|-----------------|-------------|----------------------------------------|
| `quiz_id`       | FK→quizzes  | `cascadeOnDelete`                      |
| `word`          | string(100) | set for vocab/fill-blank questions     |
| `grammar_point` | string(30)  | set for Part 5 questions               |
| `question`      | text        | sentence with `____` blank             |
| `options`       | json        | array of 4 option strings              |
| `correct_answer`| string(5)   | letter `A`–`D` (uppercased)            |
| `explanation`   | text        | Chinese rationale                      |
| `user_answer`   | string(5)   | letter, nullable                       |
| `is_correct`    | boolean     | nullable until graded                  |

#### `review_logs` — analytics event stream
One row per graded event (review, vocab quiz answer, grammar quiz answer, AI
boost). Drives all dashboard stats and adaptive grammar selection.

| Column          | Type        | Notes                                   |
|-----------------|-------------|-----------------------------------------|
| `user_id`       | FK→users    | owner, `cascadeOnDelete`                |
| `user_word_id`  | FK→user_words | nullable                              |
| `quiz_id`       | FK→quizzes  | nullable                                |
| `category`      | string(50)  | denormalized from the word              |
| `part_of_speech`| string(20)  | denormalized from the word              |
| `grammar_point` | string(30)  | for Part 5 events                       |
| `quality`       | tinyint     | 0–5 (SM-2 grade; `<3` counts as wrong)  |
| `reviewed_at`   | timestamp   | event time                              |

### 2.2 Relationships
- `User` 1—* `UserWord` / `Quiz` / `ReviewLog` (all `cascadeOnDelete`).
- `Word` 1—* `UserWord` (`UserWord.dictionary` = `belongsTo Word`).
- `Quiz` 1—* `QuizQuestion`.
- `ReviewLog` references `UserWord` and/or `Quiz` (both nullable).

---

## 3. Functional Requirements

### 3.0 Accounts & access control (`Auth\*`, `Admin\UserController`, `EnsureUserIsAdmin`)
- **FR-A1** Open registration: `GET/POST /register` validates a unique account
  id (`name`), an optional unique email, and a confirmed password (min 8);
  creates a non-admin, active user, logs them in, regenerates the session.
- **FR-A2** Login: `GET/POST /login` authenticates by **account id** (`name`)
  via the `web` guard with an added `is_active = true` constraint, so disabled
  accounts cannot sign in. Email is not used for login. `POST /logout`
  invalidates the session.
- **FR-A3** All application routes (dashboard, words, vocabulary, review, quiz)
  sit behind the `auth` middleware; guests are redirected to `login`.
- **FR-A4** Every owned query is scoped by `auth()->id()`. Route-model-bound
  `UserWord`/`Quiz` additionally assert ownership and return **403** for
  non-owners. The shared `words` cache is never user-scoped.
- **FR-A5** Admin area (`/admin/users`, `admin` middleware ⇒ `is_admin` else
  403): list users with per-user stats (vocab count, completed-quiz count, last
  activity); create users; toggle `is_active`; reset a password; delete a user
  (cascades their owned rows). An admin cannot disable or delete **their own**
  account.

### 3.1 Word lookup (`WordController`, `DictionaryService`)
- **FR-1** `GET /words/lookup?q=<term>` returns JSON for a single word.
- **FR-2** Lookup pipeline: **cache** (`words` row with a non-null
  `definition_en`) → **dictionaryapi.dev** → **Claude enrich**. A `force` flag
  bypasses the cache (used for backfilling meanings).
- **FR-3** Dictionary normalization keeps **one entry per part of speech**
  (first definition + example each), aggregates ≤6 unique synonyms, extracts
  phonetic + first available audio.
- **FR-4** Enrichment asks Claude to translate **each** meaning into Chinese in
  the same order/count, plus one overall TOEIC note; results are zipped back
  onto `meanings` and the primary `definition_zh`.
- **FR-5** A successful lookup **auto-adds** the word to `user_words` with
  `source=search` and `next_review_at=today` (idempotent via `firstOrNew`).
- **FR-6** Errors: empty term ⇒ 422; not found ⇒ 404; both as JSON messages.
- **FR-6a** **Example backfill/fallback.** Words can enter the library without
  an example (the dictionary API had none, or the word was auto-added from a
  quiz/AI suggestion). Two paths fill the gap, both persisting the example to
  the `words` cache so it is generated only once:
  - **Batch** — `php artisan words:backfill-examples` links orphaned
    `user_words` to a dictionary entry, then AI-generates an example for any
    library word still missing one (`--limit`, `--chunk` options).
  - **On the fly** — `GET /words/example?q=<term>` (`WordController::example`)
    returns/generates a single example. The daily-review card requests it when
    the answer is revealed; each vocabulary-list card exposes a "Generate
    example" button.
- **FR-6b** **Memory aids (mnemonics).** Every word can carry an AI-generated
  Chinese mnemonic (`words.mnemonic`: 諧音/字根字首/拆字/聯想), generated once
  and shared, surfaced in Word Search, My Vocabulary, and Daily Review. Words get
  it at acquisition with **no extra AI request**: `enrich()` (lookup) returns it
  alongside the Chinese/TOEIC note, and `generateNewWords()`/`generateNewsQuiz()`
  return it with each generated/extracted word. Words that predate the feature or
  arrived without one are filled two ways, both persisting to the `words` cache:
  - **Batch** — `php artisan words:backfill-mnemonics` (`--limit`, `--chunk`,
    `--user-id`) generates mnemonics for library words still missing one.
  - **On the fly** — `GET /words/mnemonic?q=<term>` (`WordController::mnemonic`)
    returns/generates a single mnemonic. The daily-review card requests it on
    reveal; the Word Search and vocabulary-list cards expose a "產生記憶小技巧"
    button.

### 3.2 Vocabulary library (`VocabularyController`)
- **FR-7** `GET /vocabulary` lists `user_words` (newest first), paginated 30,
  with the related dictionary entry eager-loaded.
- **FR-8** Filterable by `source` (exact) and `tag` (`LIKE`), preserving query
  string across pages; distinct sources offered as filters.
- **FR-9** `PUT /vocabulary/{id}` updates `notes` (≤1000) and `tags` (≤200).
- **FR-10** `DELETE /vocabulary/{id}` removes the word.

### 3.3 Spaced repetition (`ReviewController`, `SpacedRepetitionService`)
- **FR-11** Due = `next_review_at <= now`. `GET /review` lists due words ordered
  by due date.
- **FR-12** `POST /review/{userWord}/grade` with `quality` 0–5 applies SM-2 (see
  §4.1) and returns `{ok, next_review_at, interval_days}` as JSON.
- **FR-13** Every grade writes a `review_log` (denormalizing the word's category
  and POS).
- **FR-14** `demote(word, source)` is the "got it wrong / flagged" path:
  creates the user_word if missing (EF 2.2) or lowers EF by 0.2 (floor 1.3),
  resets repetitions/interval, increments `lapses`, schedules for **today**, and
  logs quality 1.
- **FR-15** A word with `lapses >= 4` is a **leech** (`UserWord::leeches()`
  scope, `is_leech` accessor).

### 3.4 Quiz generation (`QuizController`, `QuizBuilderService`, `ClaudeService`)
- **FR-16** `POST /quiz` validates: `type∈{vocab_mc,part5_grammar,fill_blank}`,
  `mode` (string), `count` 1–20, optional `category`, `level` 1–5, `points[]`.
- **FR-17** Requires an API key; otherwise returns with an error banner.
- **FR-18** **Vocab/fill-blank**: `QuizBuilderService::selectWords()` chooses
  words by mode (§4.2), then Claude generates one question per word.
- **FR-19** **Part 5**: `selectGrammarPoints()` chooses grammar codes
  (adaptive or specific, §4.3); Claude generates one question per code.
- **FR-20** Persist a `Quiz` (`status=pending`) and its valid `QuizQuestion`s
  (skipping any without `question`/`options`); `correct_answer` uppercased;
  `total` corrected to the actual stored count. Each question's options are
  **shuffled** at save and the correct letter remapped (`shuffleOptions`), so
  the answer isn't biased toward a fixed position (LLMs favor "A"); prompts
  therefore explain by option content, not letter. Applies to all generated
  quiz types (vocab/Part 5/fill-blank/news).
- **FR-21** Any new `word` referenced by a question is ensured present in the
  library (`source=quiz_new`, due today).
- **FR-22** Empty generation ⇒ error banner, no quiz created.

### 3.4a News-reading quiz (`QuizController::storeNews`, `NewsService`, `ClaudeService::generateNewsQuiz`)
- **FR-22a** `POST /quiz` with `type=news_reading` and an optional `topic`
  (`top`\|`world`\|`business`\|`technology`) builds a reading quiz from a recent
  BBC article. Requires an API key (else error banner, like other AI quizzes).
- **FR-22b** `NewsService::pickArticle()` reads the topic's BBC RSS feed, skips
  articles the user already did (matched against recent `news_reading` quizzes'
  `article.url`), and attaches a **reading passage**: best-effort full text
  scraped from the article page (`<p>` extraction, capped ~1800 chars), falling
  back to the RSS summary when scraping fails or yields too little. No usable
  article ⇒ error banner.
- **FR-22c** A single `generateNewsQuiz(title, passage, learnerProfile)` call
  returns, in one shot: a difficulty `level` (1–5) and a `suitable` verdict for
  *this* learner (profile = library size + recent quiz accuracy), a Chinese
  `suitability_note` and `summary`, ~4 reading-comprehension questions, and
  5–8 extracted vocab words. Comprehension questions store neither `word` nor
  `grammar_point`.
- **FR-22d** Extracted vocab is added to the library (`addNewsVocab`): each word
  is upserted into the shared `words` cache **without clobbering** a richer
  existing entry, then added to `user_words` with `source=news` and
  `next_review_at=today` (idempotent) — so it immediately enters the SM-2 review
  queue and weak-word quizzes. The difficulty verdict is shown but never blocks
  generation; the create form offers picking another article.

### 3.5 Taking & grading (`QuizController`)
- **FR-23** `GET /quiz/{quiz}` shows the quiz; a completed quiz redirects to
  review.
- **FR-24** `POST /quiz/{quiz}/submit` accepts `answers[questionId]=letter`.
  Per question: record `user_answer`/`is_correct`; then:
  - vocab/fill-blank with a `word`: correct ⇒ `gradeByWord(word, 4)`; wrong ⇒
    `demote(word, 'quiz_weak')`.
  - grammar: write a `review_log` with `quality = correct ? 5 : 1` and the
    `grammar_point` (+ denormalized category/POS if the word exists in `words`).
  - news comprehension (no `word`, no `grammar_point`): counts toward `score`
    only — **no** `review_log` (it has no category/POS/grammar dimension and
    would otherwise pollute the weakness stats).
- **FR-25** Save `score`, `status=completed`, `completed_at`, then redirect to
  review.

### 3.6 AI review (`QuizController::review`, `ClaudeService::reviewQuiz`)
- **FR-26** On first view of a completed quiz, generate `ai_review`
  (`{summary, weaknesses, review_words[], review_grammar[]}`) once and persist.
- **FR-27** **Vocab quizzes only**: each AI-suggested `review_word` is looked up
  via the dictionary; if it resolves to a real word **not already tested in this
  quiz**, it's `demote`d with `source=ai_review` (avoids double-counting and
  function words). Grammar quizzes are instructed to return empty `review_words`.
- **FR-28** AI-suggested weak grammar points are normalized (code or Chinese
  label) and recorded as `review_log` rows with `quality=1`
  (`boostGrammar`), nudging the adaptive selector. These decay naturally as the
  point is later answered correctly.
- **FR-28a** **News quizzes** get comprehension-focused feedback
  (`reviewNewsQuiz`) and return empty `review_words`/`review_grammar`: their
  vocab loop already closed at creation (FR-22d), and comprehension questions
  map to no vocab word or grammar point.

### 3.7 Dashboard (`DashboardController`)
- **FR-29** Cards: total words, due today, completed quizzes, average accuracy.
- **FR-30** Recent score trend (last 10 completed quizzes, chronological).
- **FR-31** Grammar weakness ranking: per `grammar_point`, error rate =
  `wrong/total` where wrong = `quality<3`, sorted desc.
- **FR-32** Vocab weakness by part of speech (same error-rate computation).
- **FR-33** Leech list (top 10 by `lapses`).
- **FR-34** Latest AI-recommended grammar focus from recent reviews
  (de-duplicated, human-labeled).

### 3.8 Responsive UI
- **FR-35** Top navigation shows inline links ≥768px and a collapsible
  hamburger menu <768px (Alpine.js toggle). `[x-cloak]` prevents flash on load.

---

## 4. Algorithms

### 4.1 SM-2 spaced repetition (`SpacedRepetitionService::grade`)
Input: a `UserWord` and `quality ∈ [0,5]` (clamped).

```
if quality < 3:                      # failed recall
    repetitions = 0
    interval_days = 1
    lapses += 1
else:                                # recalled
    repetitions += 1
    interval_days = repetitions == 1 ? 1
                  : repetitions == 2 ? 6
                  : round(interval_days * ease_factor)

# SuperMemo-2 ease-factor update
ease_factor = max(1.3,
    round(ease_factor + (0.1 - (5-quality)*(0.08 + (5-quality)*0.02)), 2))

last_reviewed_at = now
next_review_at  = now + max(1, interval_days) days
log(quality)
```

### 4.2 Word selection for quizzes (`QuizBuilderService::selectWords`)
- **`smart`** (smart mix): ~70% review + ~30% new.
  - *Review portion* = `weakWords()`: weighted **sampling without replacement**
    over the library, weight `= max(0.2, 2.7 − ease_factor)`, ×2.5 if overdue.
    Topped up with random library words if short.
  - *New portion* = `newWords()`: random unseen words from `words`
    (optionally filtered by category/level); if still short, Claude generates
    fresh words, which are persisted into `words` (`source=api`).
  - Final top-up from the catalog if still under count; truncate to `n`.
- **`weak`**: `weakWords(n)` only.
- **`custom`**: random `n` from a scope — `custom` (user library, optional tag
  filter), `builtin` (seed words), or any word — filtered by category/level.

### 4.3 Adaptive grammar-point selection (`selectGrammarPoints`)
- **`specific`**: intersect requested `points` with the valid set, then repeat
  cyclically to length `n` (`fillToN`).
- **`adaptive`**: per grammar point, `weight = 1.0` if unseen, else
  `0.2 + (wrong/total)*2.0` (wrong = `quality<3` in `review_logs`). Pick `n`
  points by **weighted random with replacement** — so heavily-missed points
  appear more often, but every point keeps a floor chance.

### 4.4 Grammar points (`QuizBuilderService::GRAMMAR_POINTS`)
`word_form`, `tense_voice`, `preposition`, `conjunction`, `pronoun`,
`relative`, `comparison`, `agreement`, `collocation`, `vocab_in_context`.
Human/Chinese labels are mapped in the dashboard and in
`QuizController::normalizeGrammarPoint`.

---

## 5. External Integrations

### 5.1 Anthropic Claude (`ClaudeService`)
- **Endpoint**: `POST https://api.anthropic.com/v1/messages`, headers
  `x-api-key`, `anthropic-version: 2023-06-01`. Model from
  `services.anthropic.model` (default `claude-sonnet-4-6`). 90s HTTP timeout.
- **Calls**:
  - `enrich(word, dictData)` — per-POS Chinese + TOEIC note + mnemonic (≤1024 tokens).
  - `generateNewWords(n, level?, category?)` — fresh TOEIC words, each with a
    mnemonic (≤2048).
  - `generateMnemonics(items[])` — Chinese memory aid per word, as a
    `{word: mnemonic}` map (≤4096, retries up to 3×). Backs the
    `words:backfill-mnemonics` command and the on-the-fly card/reveal fallback.
  - `generateExamples(words[])` — TOEIC-style example sentence per word, as a
    `{word: sentence}` map (≤4096 tokens). Backs the
    `words:backfill-examples` command and the on-the-fly card fallback.
  - `generateQuiz(items, type)` — vocab/Part 5/fill-blank questions (≤4096).
  - `generateNewsQuiz(title, passage, profile)` — difficulty verdict + ~4
    comprehension questions + 5–8 extracted words (each with a mnemonic), in one
    call (≤4096).
  - `reviewQuiz(quiz)` — summary, weaknesses, review words/grammar (≤2048);
    delegates to `reviewNewsQuiz()` for news quizzes (comprehension feedback,
    empty review words/grammar).
- **Output contract**: every prompt demands **JSON only**. `extractJson()`
  strips ```` ```json ```` fences, attempts a direct decode, then falls back to
  slicing from the first `{`/`[` to the last `}`/`]`. Failures are logged and
  yield `null`/`[]` so callers degrade safely.
- **Resilience**: network/non-200 errors are caught and logged; AI features
  return empty results rather than throwing. Two classes of malformed-JSON
  failure are handled specifically:
  - **Truncation** — a large batch can exceed the token budget and cut the JSON
    mid-stream. Mitigated by a generous `max_tokens` (4096 for example
    generation) and by callers batching in small chunks (the backfill command
    defaults to 20 words/request and can be lowered via `--chunk`).
  - **Delimiter glitches** — the model intermittently emits a comma instead of a
    colon between a key and value (e.g. `"slip","…"`), invalidating the whole
    object. `generateExamples()` therefore **retries up to 3 times**; since each
    request is independent, a retry almost always parses cleanly. Per-word
    matching is case-insensitive, so a partial batch still salvages whatever
    parsed.

### 5.2 Free Dictionary API (`DictionaryService`)
- `GET {DICTIONARY_API_URL}/{term}`, 15s timeout, no key required. Non-200 or
  malformed responses return the cached word (possibly `null`).

### 5.3 BBC News RSS (`NewsService`)
- Reads public BBC RSS feeds (`services.news.feeds`, keyed by topic), 15s
  timeout, **no key required**, browser-like `User-Agent`
  (`services.news.user_agent`). `recentArticles()` parses RSS items via
  SimpleXML; `fetchPassage()` best-effort scrapes article `<p>` text. All
  network/parse failures are caught + logged and degrade to an empty list / RSS
  summary, so a flaky feed never 500s the request.

---

## 6. Configuration

| Variable             | Required | Default                                           |
|----------------------|----------|---------------------------------------------------|
| `ANTHROPIC_API_KEY`  | for AI   | —                                                 |
| `ANTHROPIC_MODEL`    | no       | `claude-sonnet-4-6`                               |
| `DICTIONARY_API_URL` | no       | `https://api.dictionaryapi.dev/api/v2/entries/en` |
| `NEWS_FEED_*`        | no       | BBC RSS per topic (`TOP`/`WORLD`/`BUSINESS`/`TECHNOLOGY`) |
| `NEWS_USER_AGENT`    | no       | browser-like UA for fetching RSS/articles         |
| `DB_*`               | yes      | MySQL (Docker) / SQLite (local)                   |

Config keys live in `config/services.php` under `anthropic.*`,
`dictionary.url`, and `news.*`.

---

## 7. Seed Data
`WordSeeder` upserts ~142 curated TOEIC words (`source=seed`) across 10
categories — business, contracts, finance, general, hr, logistics, marketing,
office, technology, travel — each with POS, Chinese meaning, example, and level.
Idempotent via `updateOrCreate` on `word`.

The `seed_wells_admin_and_backfill` migration also creates a default **admin**
(login id `wells`, password `password` — change after first login) and assigns
any pre-existing single-user rows to it, before switching the `user_words`
unique key from `word` to `(user_id, word)`.

---

## 8. Security & Privacy
- **SEC-1** Session-cookie authentication (Laravel `web` guard). All app routes
  require `auth`; the admin area additionally requires `is_admin`. Per-user data
  is isolated by `user_id`, with ownership re-checked on bound routes (403 on
  mismatch). Disabled accounts (`is_active=false`) cannot log in.
- **SEC-2** The Anthropic API key lives only in `.env`, which is git-ignored and
  kept out of version control. Rotate it in the Anthropic Console if exposed.
- **SEC-3** All write routes are CSRF-protected (Laravel default; meta tag in
  the layout). Lookups and grading return JSON.
- **SEC-4** User-supplied search terms and AI-suggested words are validated and
  resolved through the dictionary before being persisted/scheduled, preventing
  empty or junk cards.
- **SEC-5** Inputs are validated (`type`, `count` 1–20, `level` 1–5, `quality`
  0–5, string length caps on notes/tags).

---

## 9. Known Limitations / Future Work
- Single-user; no accounts, no per-user isolation.
- Quiz generation is synchronous (request blocks up to ~90s on the AI call); a
  queued/async flow would improve UX for large quizzes.
- Tags are free-text matched by `LIKE` (no normalized tag table).
- Only TOEIC Part 5–style grammar and vocabulary are covered, not full-test
  reading/listening sections.
- The browser TTS fallback quality depends on the device's installed voices.
