from __future__ import annotations

import argparse
import csv
import hashlib
import json
import random
import re
import unicodedata
from collections import Counter, defaultdict
from difflib import SequenceMatcher
from itertools import combinations
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
ORIGINAL = ROOT / "data" / "labeled" / "sfms_sentiment_master.csv"
MASTER_OUTPUT = ROOT / "data" / "labeled" / "sfms_sentiment_v3_5000.csv"
SPLIT_DIR = ROOT / "data" / "splits" / "v3_5000"
SUMMARY_OUTPUT = ROOT / "reports" / "dataset_summary_v3_5000.json"
VALIDATION_OUTPUT = ROOT / "reports" / "dataset_validation_v3_5000.md"
SEED = 20261117
SIMILARITY_THRESHOLD = 0.88

LANGUAGES = ("English", "Filipino/Tagalog", "Bisaya/Cebuano")
LANGUAGE_CODES = {
    "English": "EN",
    "Filipino/Tagalog": "TL",
    "Bisaya/Cebuano": "CEB",
}
LABELS = ("Positive", "Negative", "Neutral")
LABEL_CODES = {"Positive": "P", "Negative": "N", "Neutral": "U"}
SPLIT_RATIOS = {"train": 0.70, "validation": 0.15, "test": 0.15}
SPLIT_TARGETS = {"train": 3500, "validation": 750, "test": 750}
LANGUAGE_TARGETS = {
    "English": 1667,
    "Filipino/Tagalog": 1667,
    "Bisaya/Cebuano": 1666,
}
LANGUAGE_LABEL_TARGETS = {
    "English": {"Positive": 556, "Negative": 556, "Neutral": 555},
    "Filipino/Tagalog": {"Positive": 556, "Negative": 555, "Neutral": 556},
    "Bisaya/Cebuano": {"Positive": 555, "Negative": 556, "Neutral": 555},
}
FIELDS = [
    "record_id",
    "text",
    "label",
    "language_group",
    "topic",
    "source_group",
    "duplicate_group",
    "annotator",
    "review_status",
]

CONTEXTS = {
    "English": [
        "my first visit to the community health center",
        "a scheduled outpatient appointment",
        "a follow-up visit at the clinic",
        "the morning clinic session",
        "a visit after a referral",
        "my return visit to the facility",
        "a weekday appointment at the public clinic",
        "my recent visit to the health center",
    ],
    "Filipino/Tagalog": [
        "una kong pagbisita sa sentrong pangkalusugan",
        "nakaiskedyul kong konsultasyon sa klinika",
        "pagbalik ko sa klinika para sa muling pagsusuri",
        "sesyon ng klinika sa umaga",
        "pagbisita ko matapos akong isangguni sa klinika",
        "pagbalik ko sa pampublikong klinika",
        "nakaiskedyul kong pagpunta sa pagamutan",
        "kamakailan kong pagbisita sa sentrong pangkalusugan",
    ],
    "Bisaya/Cebuano": [
        "una nako nga pag-adto sa sentro sa panglawas",
        "gikatakdang konsultasyon sa klinika",
        "pagbalik nako sa klinika alang sa sunod nga pagsusi",
        "sesyon sa klinika sa buntag",
        "pagbisita nako human ko gisangguni sa klinika",
        "pagbalik nako sa pampublikong klinika",
        "gikatakdang pag-adto sa pasilidad",
        "bag-o lang nga pagbisita nako sa sentro sa panglawas",
    ],
}

FRAMES = {
    "English": {
        "Positive": [
            "During {context}, {actor} {action}.",
            "I appreciated how {actor} {action} during {context}.",
            "The {service} felt reassuring because {actor} {action} during {context}.",
            "At {context}, I was pleased that {actor} {action}.",
        ],
        "Negative": [
            "During {context}, {actor} {action}.",
            "I was concerned when {actor} {action} during {context}.",
            "The {service} was frustrating because {actor} {action} during {context}.",
            "At {context}, I felt let down when {actor} {action}.",
        ],
        "Neutral": [
            "Could the clinic {action} for {context}?",
            "For {context}, I would like to know whether the clinic can {action}.",
            "Please consider whether the clinic can {action} for {context}.",
            "Could a short notice explain how to {action} for {context}?",
        ],
    },
    "Filipino/Tagalog": {
        "Positive": [
            "Sa {context}, {actor} ay {action}.",
            "Nagustuhan ko na {actor} ay {action} sa {context}.",
            "Maganda ang naging karanasan ko sa {context} dahil {actor} ay {action}.",
            "Pinahahalagahan ko ang pag-asikaso sa {context}: {actor} ay {action}.",
        ],
        "Negative": [
            "Sa {context}, {actor} ay {action}.",
            "Nadismaya ako dahil {actor} ay {action} sa {context}.",
            "Naging mahirap ang {context} dahil {actor} ay {action}.",
            "Nag-alala ako nang {actor} ay {action} sa {context}.",
        ],
        "Neutral": [
            "Maaari bang {action} para sa {context}?",
            "Nais ko sanang malaman kung maaari bang {action} para sa {context}.",
            "Pakiusap na {action} para sa {context}.",
            "Mungkahi ko na {action} para sa {context}.",
        ],
    },
    "Bisaya/Cebuano": {
        "Positive": [
            "Sa {context}, {actor} {action}.",
            "Nalipay ko kay {actor} {action} sa {context}.",
            "Maayo ang akong kasinatian sa {context} tungod kay {actor} {action}.",
            "Gipasalamatan nako nga {actor} {action} atol sa {context}.",
        ],
        "Negative": [
            "Sa {context}, {actor} {action}.",
            "Nabalaka ko kay {actor} {action} sa {context}.",
            "Nalisod ko sa {context} tungod kay {actor} {action}.",
            "Nadismaya ko kay {actor} {action} atol sa {context}.",
        ],
        "Neutral": [
            "Mahimo bang {action} alang sa {context}?",
            "Gusto ko mahibalo kung mahimo bang {action} alang sa {context}.",
            "Palihog ikonsiderar nga {action} alang sa {context}.",
            "Mungkahi nako nga {action} alang sa {context}.",
        ],
    },
}

ACTIONS = {
    "English": {
        "conversation": {
            "Positive": [
                "listened carefully while I described {detail}",
                "answered my questions about {detail} clearly",
                "explained {detail} in language I could follow",
                "checked that I understood {detail}",
                "gave me enough time to discuss {detail}",
                "summarized the next step for {detail}",
            ],
            "Negative": [
                "interrupted me while I described {detail}",
                "did not answer my questions about {detail}",
                "rushed through the explanation of {detail}",
                "gave me conflicting instructions about {detail}",
                "ended the discussion before resolving my questions about {detail}",
                "left me unsure about the next step for {detail}",
            ],
            "Neutral": [
                "post a short guide explaining {detail}",
                "share the current schedule for {detail}",
                "provide a checklist of information needed for {detail}",
                "display the steps for {detail}",
                "prepare a short information sheet about {detail}",
                "post a notice showing where to ask about {detail}",
            ],
        },
        "process": {
            "Positive": [
                "kept me informed about {detail}",
                "handled my request about {detail} carefully",
                "explained the steps for {detail}",
                "checked the requirements for {detail} with me",
                "prepared information about {detail} on schedule",
                "made the steps for {detail} clear",
            ],
            "Negative": [
                "left me waiting without an update about {detail}",
                "delayed the service related to {detail} without explaining why",
                "gave me incomplete instructions about {detail}",
                "handled my request about {detail} incorrectly",
                "could not tell me when information about {detail} would be ready",
                "sent me to another desk to ask about {detail}",
            ],
            "Neutral": [
                "post a short guide explaining {detail}",
                "share the current schedule for {detail}",
                "provide a checklist of items needed for {detail}",
                "display directions for where to ask about {detail}",
                "publish a brief update about {detail}",
                "prepare an information sheet covering {detail}",
            ],
        },
        "facility": {
            "Positive": [
                "kept {detail} in good condition",
                "maintained {detail} throughout the clinic session",
                "checked the condition of {detail} before the waiting area became busy",
                "kept {detail} comfortable for visitors",
                "kept the area around {detail} clear",
                "responded promptly when I asked about {detail}",
            ],
            "Negative": [
                "did not maintain {detail} during clinic hours",
                "did not check the condition of {detail}",
                "let the condition of {detail} worsen",
                "did not address the problem with {detail}",
                "did not keep the area around {detail} clear",
                "did not respond to my concern about {detail}",
            ],
            "Neutral": [
                "post information about the condition of {detail}",
                "display a schedule for checking {detail}",
                "provide instructions for reporting a problem with {detail}",
                "share guidance on maintaining {detail}",
                "publish the contact point for concerns about {detail}",
                "post a notice explaining how {detail} is maintained",
            ],
        },
        "access": {
            "Positive": [
                "kept the route to {detail} clear",
                "showed me how to reach {detail}",
                "made it possible to use {detail} without assistance",
                "responded promptly when I asked for access to {detail}",
                "checked that {detail} was ready before my appointment",
                "gave clear directions to {detail}",
            ],
            "Negative": [
                "left the route to {detail} blocked",
                "did not explain how to reach {detail}",
                "made it difficult to use {detail}",
                "did not respond when I asked for access to {detail}",
                "left {detail} unavailable during my visit",
                "gave unclear directions to {detail}",
            ],
            "Neutral": [
                "post a map showing how to reach {detail}",
                "display directions to {detail}",
                "share the schedule for checking {detail}",
                "provide information about using {detail}",
                "place a sign near the route to {detail}",
                "publish an access guide for {detail}",
            ],
        },
    },
    "Filipino/Tagalog": {
        "conversation": {
            "Positive": [
                "maingat na nakinig habang inilalarawan ko ang {detail}",
                "malinaw na sumagot sa mga tanong ko tungkol sa {detail}",
                "nagpaliwanag tungkol sa {detail} sa paraang madaling sundan",
                "tiniyak na naunawaan ko ang {detail}",
                "naglaan ng sapat na oras para pag-usapan ang {detail}",
                "nilinaw ang susunod na hakbang para sa {detail}",
            ],
            "Negative": [
                "sumabad habang inilalarawan ko ang {detail}",
                "hindi sumagot sa mga tanong ko tungkol sa {detail}",
                "nagmamadali sa pagpapaliwanag tungkol sa {detail}",
                "nagbigay ng magkasalungat na tagubilin tungkol sa {detail}",
                "tinapos ang usapan bago malinaw ang {detail}",
                "iniwan akong hindi tiyak sa susunod na hakbang para sa {detail}",
            ],
            "Neutral": [
                "magpaskil ang klinika ng maikling gabay tungkol sa {detail}",
                "ibahagi ng klinika ang kasalukuyang iskedyul para sa {detail}",
                "magbigay ang klinika ng listahan ng mga kailangan para sa {detail}",
                "maglagay ang klinika ng mga hakbang tungkol sa {detail}",
                "maglabas ang klinika ng maikling paunawa tungkol sa {detail}",
                "maghanda ang klinika ng papel ng impormasyon tungkol sa {detail}",
            ],
        },
        "process": {
            "Positive": [
                "nagbigay ng napapanahong update tungkol sa {detail}",
                "maingat na inasikaso ang tanong ko tungkol sa {detail}",
                "ipinaliwanag ang mga hakbang para sa {detail}",
                "sinuri ang mga kailangan para sa {detail} kasama ko",
                "inihanda ang impormasyon tungkol sa {detail} ayon sa iskedyul",
                "nilinaw ang mga hakbang para sa {detail}",
            ],
            "Negative": [
                "pinaghintay ako nang walang update tungkol sa {detail}",
                "naantala ang serbisyong may kaugnayan sa {detail} nang walang paliwanag",
                "nagbigay ng kulang na tagubilin tungkol sa {detail}",
                "nagkamali sa pag-asikaso sa tanong ko tungkol sa {detail}",
                "hindi masabi kung kailan magiging handa ang impormasyon tungkol sa {detail}",
                "pinapunta ako sa ibang mesa para magtanong tungkol sa {detail}",
            ],
            "Neutral": [
                "magpaskil ang klinika ng gabay tungkol sa {detail}",
                "ibahagi ng klinika ang kasalukuyang iskedyul para sa {detail}",
                "magbigay ang klinika ng listahan ng mga kailangan para sa {detail}",
                "maglagay ang klinika ng mga hakbang tungkol sa {detail}",
                "maglabas ang klinika ng maikling paunawa tungkol sa {detail}",
                "maghanda ang klinika ng papel ng impormasyon tungkol sa {detail}",
            ],
        },
        "facility": {
            "Positive": [
                "pinanatiling maayos ang kondisyon ng {detail}",
                "regular na sinuri ang kondisyon ng {detail}",
                "sinuri ang {detail} bago dumami ang mga bisita",
                "pinanatiling maaliwalas at komportable ang {detail}",
                "inayos agad ang problema tungkol sa {detail}",
                "agad na tumugon nang magtanong ako tungkol sa {detail}",
            ],
            "Negative": [
                "pinabayaan ang kondisyon ng {detail}",
                "hindi regular na sinuri ang kondisyon ng {detail}",
                "hindi inayos ang problema tungkol sa {detail}",
                "hindi pinanatiling maayos ang {detail}",
                "hindi tumugon sa naiulat na problema sa {detail}",
                "hindi pinansin ang tanong ko tungkol sa {detail}",
            ],
            "Neutral": [
                "magpaskil ang klinika ng impormasyon tungkol sa kondisyon ng {detail}",
                "maglagay ang klinika ng iskedyul ng pagsusuri sa {detail}",
                "magbigay ang klinika ng tagubilin kung paano mag-ulat ng problema sa {detail}",
                "magbahagi ang klinika ng gabay sa pangangalaga sa {detail}",
                "magbigay ang klinika ng contact person para sa mga alalahanin tungkol sa {detail}",
                "maglagay ang klinika ng paunawa kung paano pinananatili ang {detail}",
            ],
        },
        "access": {
            "Positive": [
                "pinanatiling maluwag ang daan papunta sa {detail}",
                "ipinaliwanag kung paano mararating ang {detail}",
                "naghanda ng {detail} para magamit nang maayos",
                "agad na tumugon nang humingi ako ng daan papunta sa {detail}",
                "sinuri kung handa ang {detail} bago ang appointment",
                "nagbigay ng malinaw na direksiyon papunta sa {detail}",
            ],
            "Negative": [
                "nag-iwan ng sagabal sa daan papunta sa {detail}",
                "hindi ipinaliwanag kung paano mararating ang {detail}",
                "naging mahirap gamitin ang {detail}",
                "hindi tumugon nang humingi ako ng daan papunta sa {detail}",
                "iniwang hindi magamit ang {detail} habang naroon ako",
                "nagbigay ng malabong direksiyon papunta sa {detail}",
            ],
            "Neutral": [
                "magpaskil ang klinika ng mapa papunta sa {detail}",
                "maglagay ang klinika ng direksiyon papunta sa {detail}",
                "ibahagi ng klinika ang iskedyul ng pagsusuri sa {detail}",
                "magbigay ang klinika ng impormasyon tungkol sa paggamit ng {detail}",
                "maglagay ang klinika ng karatula malapit sa daan papunta sa {detail}",
                "maghanda ang klinika ng gabay sa pagpunta sa {detail}",
            ],
        },
    },
    "Bisaya/Cebuano": {
        "conversation": {
            "Positive": [
                "namati pag-ayo samtang akong gihulagway ang {detail}",
                "mitubag og klaro sa akong mga pangutana bahin sa {detail}",
                "misaysay bahin sa {detail} sa paagi nga sayon sabton",
                "misiguro nga nasabtan nako ang {detail}",
                "migahin og igong panahon sa paghisgot sa {detail}",
                "mipasabot sa sunod nga lakang bahin sa {detail}",
            ],
            "Negative": [
                "misamok sa akong pagsaysay bahin sa {detail}",
                "wala motubag sa akong mga pangutana bahin sa {detail}",
                "midali sa pagpasabot bahin sa {detail}",
                "naghatag og nagkasumpaking instruksyon bahin sa {detail}",
                "gitapos ang panag-istorya sa wala pa masulbad ang akong pangutana bahin sa {detail}",
                "nagbilin kanako nga wala masayod sa sunod nga lakang bahin sa {detail}",
            ],
            "Neutral": [
                "magbutang ang klinika og mubo nga giya bahin sa {detail}",
                "ipaambit sa klinika ang kasamtangang eskedyul alang sa {detail}",
                "maghatag ang klinika og lista sa mga kinahanglanon alang sa {detail}",
                "magpaskil ang klinika og mga lakang bahin sa {detail}",
                "mohatag ang klinika og pahibalo bahin sa {detail}",
                "mag-andam ang klinika og papel nga adunay impormasyon bahin sa {detail}",
            ],
        },
        "process": {
            "Positive": [
                "kanunay nga naghatag og update bahin sa {detail}",
                "maampingong mitubag sa akong hangyo bahin sa {detail}",
                "mipasabot sa mga lakang alang sa {detail}",
                "misusi sa mga kinahanglanon alang sa {detail} uban kanako",
                "giandam ang impormasyon bahin sa {detail} sumala sa eskedyul",
                "gipasayon ang pagsabot sa mga lakang alang sa {detail}",
            ],
            "Negative": [
                "nagpaabot kanako nga walay update bahin sa {detail}",
                "naulahi ang serbisyo nga may kalabotan sa {detail} nga walay pagpasabot",
                "naghatag og kulang nga instruksyon bahin sa {detail}",
                "nasayop sa pagdumala sa akong hangyo bahin sa {detail}",
                "wala makasulti kanako kung kanus-a andam ang impormasyon bahin sa {detail}",
                "gipadala ko sa laing lamesa aron mangutana bahin sa {detail}",
            ],
            "Neutral": [
                "magbutang ang klinika og mubo nga giya bahin sa {detail}",
                "ipaambit sa klinika ang kasamtangang eskedyul alang sa {detail}",
                "maghatag ang klinika og lista sa mga kinahanglanon alang sa {detail}",
                "magpaskil ang klinika og mga lakang bahin sa {detail}",
                "mohatag ang klinika og pahibalo bahin sa {detail}",
                "mag-andam ang klinika og papel nga adunay impormasyon bahin sa {detail}",
            ],
        },
        "facility": {
            "Positive": [
                "nagmintinar sa maayong kahimtang sa {detail}",
                "kanunay nga misusi sa kahimtang sa {detail}",
                "misusi sa {detail} sa wala pa modaghan ang mga bisita",
                "nagpabiling komportable ug hapsay ang {detail}",
                "giayo dayon ang problema bahin sa {detail}",
                "mitubag dayon sa akong pangutana bahin sa {detail}",
            ],
            "Negative": [
                "gipasagdan ang kahimtang sa {detail}",
                "wala kanunay susiha ang kahimtang sa {detail}",
                "wala giayo ang problema bahin sa {detail}",
                "wala gipabiling hapsay ang {detail}",
                "wala motubag sa gitaho nga problema sa {detail}",
                "wala tagda ang akong pangutana bahin sa {detail}",
            ],
            "Neutral": [
                "magpaskil ang klinika og impormasyon bahin sa kahimtang sa {detail}",
                "magbutang ang klinika og eskedyul sa pagsusi sa {detail}",
                "maghatag ang klinika og instruksyon kung giunsa pagreport ang problema sa {detail}",
                "ipaambit sa klinika ang giya sa pagmentinar sa {detail}",
                "maghatag ang klinika og tawong kontakon bahin sa mga problema sa {detail}",
                "magbutang ang klinika og pahibalo kung giunsa pagmentinar ang {detail}",
            ],
        },
        "access": {
            "Positive": [
                "nagpabiling walay babag ang agianan paingon sa {detail}",
                "mipasabot kung giunsa pag-adto sa {detail}",
                "nangandam sa {detail} aron magamit sa husto",
                "mitubag dayon sa akong hangyo nga makaadto sa {detail}",
                "misusi kung andam ba ang {detail} sa wala pa ang appointment",
                "naghatag og klarong direksyon paingon sa {detail}",
            ],
            "Negative": [
                "nagbilin og babag sa agianan paingon sa {detail}",
                "wala mipasabot kung giunsa pag-adto sa {detail}",
                "nakapalisod sa paggamit sa {detail}",
                "wala mitubag sa akong hangyo nga makaadto sa {detail}",
                "wala magamit ang {detail} atol sa akong pagbisita",
                "naghatag og dili klarong direksyon paingon sa {detail}",
            ],
            "Neutral": [
                "magpaskil ang klinika og mapa paingon sa {detail}",
                "magbutang ang klinika og direksyon paingon sa {detail}",
                "ipaambit sa klinika ang eskedyul sa pagsusi sa {detail}",
                "maghatag ang klinika og impormasyon sa paggamit sa {detail}",
                "magbutang ang klinika og karatula duol sa agianan paingon sa {detail}",
                "mag-andam ang klinika og giya sa pag-adto sa {detail}",
            ],
        },
    },
}

TOPICS = [
    {
        "id": "doctor_consultation",
        "name": {"English": "doctor consultation", "Filipino/Tagalog": "konsultasyon sa doktor", "Bisaya/Cebuano": "konsultasyon sa doktor"},
        "category": "conversation",
        "actor": {"English": "the doctor", "Filipino/Tagalog": "ang doktor", "Bisaya/Cebuano": "ang doktor"},
        "details": {
            "English": ["my symptoms", "the treatment options", "the reason for a test", "my follow-up plan"],
            "Filipino/Tagalog": ["mga sintomas ko", "mga opsyon sa gamutan", "dahilan ng pagsusuri", "plano ko para sa susunod na pagbisita"],
            "Bisaya/Cebuano": ["akong mga sintomas", "mga kapilian sa pagtambal", "rason sa pagsusi", "akong plano sa sunod nga pagbisita"],
        },
    },
    {
        "id": "nursing_care",
        "name": {"English": "nursing care", "Filipino/Tagalog": "pangangalaga ng nars", "Bisaya/Cebuano": "pag-atiman sa nars"},
        "category": "conversation",
        "actor": {"English": "the nurse", "Filipino/Tagalog": "ang nars", "Bisaya/Cebuano": "ang nars"},
        "details": {
            "English": ["my medication schedule", "wound care instructions", "discharge instructions", "my next checkup"],
            "Filipino/Tagalog": ["iskedyul ng gamot ko", "tagubilin sa pag-aalaga ng sugat", "tagubilin bago umuwi", "susunod kong pagsusuri"],
            "Bisaya/Cebuano": ["eskedyul sa akong tambal", "instruksyon sa pag-atiman sa samad", "instruksyon sa dili pa mouli", "sunod nakong pagsusi"],
        },
    },
    {
        "id": "waiting_queue",
        "name": {"English": "waiting time and queue management", "Filipino/Tagalog": "oras ng paghihintay at pila", "Bisaya/Cebuano": "oras sa paghulat ug pila"},
        "category": "process",
        "actor": {"English": "the reception team", "Filipino/Tagalog": "ang mga kawani sa pagtanggap", "Bisaya/Cebuano": "ang mga kawani sa pagdawat"},
        "details": {
            "English": ["the estimated waiting time", "the order of the queue", "my queue number", "updates about a delay"],
            "Filipino/Tagalog": ["tinatayang oras ng paghihintay", "pagkakasunod-sunod sa pila", "numero ko sa pila", "balita tungkol sa pagkaantala"],
            "Bisaya/Cebuano": ["gibanabanang oras sa paghulat", "sunod-sunod sa pila", "akong numero sa pila", "balita bahin sa pagkalangan"],
        },
    },
    {
        "id": "triage",
        "name": {"English": "triage and initial assessment", "Filipino/Tagalog": "paunang pagsusuri at pag-uuri", "Bisaya/Cebuano": "unang pagsusi ug paghan-ay"},
        "category": "conversation",
        "actor": {"English": "the triage nurse", "Filipino/Tagalog": "ang nars sa paunang pagsusuri", "Bisaya/Cebuano": "ang nars sa unang pagsusi"},
        "details": {
            "English": ["the urgency of my symptoms", "the order of assessment", "where I should go next", "the reason for the next step"],
            "Filipino/Tagalog": ["pagkamadalian ng mga sintomas ko", "pagkakasunod-sunod ng pagsusuri", "susunod kong pupuntahan", "dahilan ng susunod na hakbang"],
            "Bisaya/Cebuano": ["pagkadinalian sa akong mga sintomas", "sunod-sunod sa pagsusi", "sunod nakong adtoan", "rason sa sunod nga lakang"],
        },
    },
    {
        "id": "laboratory_testing",
        "name": {"English": "laboratory testing", "Filipino/Tagalog": "pagsusuri sa laboratoryo", "Bisaya/Cebuano": "pagsusi sa laboratoryo"},
        "category": "process",
        "actor": {"English": "the laboratory staff", "Filipino/Tagalog": "ang mga kawani sa laboratoryo", "Bisaya/Cebuano": "ang mga kawani sa laboratoryo"},
        "details": {
            "English": ["sample collection", "preparation before the test", "the testing procedure", "requirements for the laboratory test"],
            "Filipino/Tagalog": ["pagkuha ng sample", "paghahanda bago ang pagsusuri", "paraan ng pagsusuri", "mga kailangan para sa pagsusuri"],
            "Bisaya/Cebuano": ["pagkuha sa sample", "pagpangandam sa dili pa ang pagsusi", "paagi sa pagsusi", "mga kinahanglanon sa laboratoryo"],
        },
    },
    {
        "id": "laboratory_results",
        "name": {"English": "laboratory result release", "Filipino/Tagalog": "paglabas ng resulta sa laboratoryo", "Bisaya/Cebuano": "pagpagawas sa resulta sa laboratoryo"},
        "category": "process",
        "actor": {"English": "the results desk team", "Filipino/Tagalog": "ang mga kawani sa paglabas ng resulta", "Bisaya/Cebuano": "ang mga kawani sa paghatag sa resulta"},
        "details": {
            "English": ["when my results would be ready", "where to collect the results", "what the result summary meant", "the next step after the test"],
            "Filipino/Tagalog": ["kung kailan handa ang resulta", "kung saan kukunin ang resulta", "kahulugan ng buod ng resulta", "susunod na hakbang pagkatapos ng pagsusuri"],
            "Bisaya/Cebuano": ["kanus-a andam ang resulta", "asa kuhaon ang resulta", "kahulugan sa katingbanan sa resulta", "sunod nga lakang human sa pagsusi"],
        },
    },
    {
        "id": "medical_documents",
        "name": {"English": "medical documents and certificates", "Filipino/Tagalog": "mga dokumentong medikal at sertipiko", "Bisaya/Cebuano": "mga dokumentong medikal ug sertipiko"},
        "category": "process",
        "actor": {"English": "the records clerk", "Filipino/Tagalog": "ang kawani ng talaan", "Bisaya/Cebuano": "ang kawani sa mga rekord"},
        "details": {
            "English": ["requirements for a medical certificate", "a copy of my health record", "the information on the form", "the release time for a document"],
            "Filipino/Tagalog": ["mga kailangan para sa sertipikong medikal", "kopya ng talaang pangkalusugan ko", "impormasyon sa form", "oras ng paglabas ng dokumento"],
            "Bisaya/Cebuano": ["mga kinahanglanon alang sa sertipiko medikal", "kopya sa akong rekord sa panglawas", "impormasyon sa porma", "oras sa pagpagawas sa dokumento"],
        },
    },
    {
        "id": "nutrition_counseling",
        "name": {"English": "nutrition counseling", "Filipino/Tagalog": "pagpapayo tungkol sa nutrisyon", "Bisaya/Cebuano": "tambag bahin sa nutrisyon"},
        "category": "conversation",
        "actor": {"English": "the nutrition counselor", "Filipino/Tagalog": "ang tagapayo sa nutrisyon", "Bisaya/Cebuano": "ang tigtambag sa nutrisyon"},
        "details": {
            "English": ["my meal plan", "my dietary needs", "food choices for my condition", "my nutrition goals"],
            "Filipino/Tagalog": ["plano ko sa pagkain", "pangangailangan ko sa pagkain", "mga pagkaing angkop sa kondisyon ko", "mga layunin ko sa nutrisyon"],
            "Bisaya/Cebuano": ["akong plano sa pagkaon", "akong mga panginahanglan sa pagkaon", "mga pagkaon nga angay sa akong kahimtang", "akong mga tumong sa nutrisyon"],
        },
    },
    {
        "id": "mental_health_counseling",
        "name": {"English": "mental health counseling", "Filipino/Tagalog": "pagpapayo para sa kalusugang pangkaisipan", "Bisaya/Cebuano": "tambag alang sa kahimsog sa pangisip"},
        "category": "conversation",
        "actor": {"English": "the counselor", "Filipino/Tagalog": "ang tagapayo", "Bisaya/Cebuano": "ang tigtambag"},
        "details": {
            "English": ["my concerns", "ways to manage stress", "privacy during the session", "available follow-up support"],
            "Filipino/Tagalog": ["mga alalahanin ko", "mga paraan para harapin ang stress", "pagkapribado sa sesyon", "suporta para sa susunod na pagbisita"],
            "Bisaya/Cebuano": ["akong mga kabalaka", "mga paagi sa pagdumala sa tensiyon", "pagkapribado atol sa sesyon", "suporta sa sunod nga pagbisita"],
        },
    },
    {
        "id": "pharmacy_medicines",
        "name": {"English": "pharmacy and medicine access", "Filipino/Tagalog": "botika at pagkuha ng gamot", "Bisaya/Cebuano": "botika ug pagkuha sa tambal"},
        "category": "process",
        "actor": {"English": "the pharmacy staff", "Filipino/Tagalog": "ang mga kawani sa botika", "Bisaya/Cebuano": "ang mga kawani sa botika"},
        "details": {
            "English": ["availability of my medicine", "the dosage schedule", "the refill process", "possible side effects"],
            "Filipino/Tagalog": ["pagkakaroon ng gamot ko", "iskedyul ng tamang dosis", "paraan ng muling pagkuha ng gamot", "posibleng epekto ng gamot"],
            "Bisaya/Cebuano": ["pagkaanaa sa akong tambal", "eskedyul sa dosis", "paagi sa pagkuha pag-usab sa tambal", "posibleng epekto sa tambal"],
        },
    },
    {
        "id": "registration_information",
        "name": {"English": "registration and service information", "Filipino/Tagalog": "pagpaparehistro at impormasyon sa serbisyo", "Bisaya/Cebuano": "pagparehistro ug impormasyon sa serbisyo"},
        "category": "process",
        "actor": {"English": "the registration staff", "Filipino/Tagalog": "ang mga kawani sa pagpaparehistro", "Bisaya/Cebuano": "ang mga kawani sa pagparehistro"},
        "details": {
            "English": ["check-in requirements", "the appointment form", "where to submit my form", "the next step after registration"],
            "Filipino/Tagalog": ["mga kailangan sa pagpaparehistro", "form para sa appointment", "kung saan isusumite ang form", "susunod na hakbang pagkatapos magparehistro"],
            "Bisaya/Cebuano": ["mga kinahanglanon sa pagparehistro", "porma alang sa appointment", "asa isumite ang porma", "sunod nga lakang human magparehistro"],
        },
    },
    {
        "id": "cleanliness_sanitation",
        "name": {"English": "facility cleanliness and sanitation", "Filipino/Tagalog": "kalinisan at sanitasyon ng pasilidad", "Bisaya/Cebuano": "kalimpyo ug sanitasyon sa pasilidad"},
        "category": "facility",
        "actor": {"English": "the facilities staff", "Filipino/Tagalog": "ang mga kawani sa pasilidad", "Bisaya/Cebuano": "ang mga kawani sa pasilidad"},
        "details": {
            "English": ["the washroom", "the waiting area surfaces", "the treatment room", "the waste bins"],
            "Filipino/Tagalog": ["palikuran", "mga ibabaw sa lugar-paghihintayan", "silid-paggamot", "mga basurahan"],
            "Bisaya/Cebuano": ["kasilyas", "mga lingkoranan ug lamesa sa hulatanan", "lawak sa pagtambal", "mga basurahan"],
        },
    },
    {
        "id": "seating_ventilation",
        "name": {"English": "seating and ventilation", "Filipino/Tagalog": "upuan at bentilasyon", "Bisaya/Cebuano": "lingkoranan ug bentilasyon"},
        "category": "facility",
        "actor": {"English": "the clinic staff", "Filipino/Tagalog": "ang mga kawani sa klinika", "Bisaya/Cebuano": "ang mga kawani sa klinika"},
        "details": {
            "English": ["seats in the waiting area", "airflow in the consultation room", "the room temperature", "space for visitors to sit"],
            "Filipino/Tagalog": ["mga upuan sa lugar-paghihintayan", "daloy ng hangin sa silid-konsultasyon", "temperatura ng silid", "lugar na mauupuan ng mga bisita"],
            "Bisaya/Cebuano": ["mga lingkoranan sa hulatanan", "dagan sa hangin sa lawak sa konsultasyon", "temperatura sa lawak", "lugar nga lingkoranan sa mga bisita"],
        },
    },
    {
        "id": "physical_accessibility",
        "name": {"English": "physical accessibility", "Filipino/Tagalog": "pisikal na aksesibilidad", "Bisaya/Cebuano": "pisikal nga pagkaabot sa pasilidad"},
        "category": "access",
        "actor": {"English": "the staff assisting visitors", "Filipino/Tagalog": "ang mga kawani na gumagabay sa mga pasyente", "Bisaya/Cebuano": "ang mga kawani nga motabang sa mga pasyente"},
        "details": {
            "English": ["the wheelchair ramp", "the accessible entrance", "the path to the clinic", "the accessible washroom"],
            "Filipino/Tagalog": ["rampa para sa wheelchair", "pasukang madaling daanan", "daan papunta sa klinika", "palikurang madaling marating"],
            "Bisaya/Cebuano": ["rampa alang sa wheelchair", "agianan nga dali sudlan", "agianan paingon sa klinika", "kasilyas nga dali maagian"],
        },
    },
    {
        "id": "vaccination_outreach",
        "name": {"English": "vaccination and public health outreach", "Filipino/Tagalog": "pagbabakuna at serbisyong pangkalusugan sa komunidad", "Bisaya/Cebuano": "pagbakuna ug serbisyong panglawas sa komunidad"},
        "category": "process",
        "actor": {"English": "the vaccination team", "Filipino/Tagalog": "ang mga kawani sa pagbabakuna", "Bisaya/Cebuano": "ang mga kawani sa pagbakuna"},
        "details": {
            "English": ["the vaccination schedule", "eligibility for the vaccine", "aftercare instructions", "the outreach schedule in the community"],
            "Filipino/Tagalog": ["iskedyul ng pagbabakuna", "mga kuwalipikasyon para sa bakuna", "tagubilin pagkatapos magpabakuna", "iskedyul ng pagbisita sa komunidad"],
            "Bisaya/Cebuano": ["eskedyul sa pagbakuna", "mga kinahanglan aron makabakuna", "instruksyon human magpabakuna", "eskedyul sa pagbisita sa komunidad"],
        },
    },
    {
        "id": "care_coordination",
        "name": {"English": "care coordination and patient satisfaction", "Filipino/Tagalog": "pagsasaayos ng pangangalaga at kasiyahan ng pasyente", "Bisaya/Cebuano": "koordinasyon sa pag-atiman ug katagbawan sa pasyente"},
        "category": "conversation",
        "actor": {"English": "the care team", "Filipino/Tagalog": "ang pangkat ng pangangalaga", "Bisaya/Cebuano": "ang grupo sa pag-atiman"},
        "details": {
            "English": ["my care plan", "the referral process", "my next appointment", "instructions for continued care"],
            "Filipino/Tagalog": ["plano ko sa pangangalaga", "paraan ng pagsangguni", "susunod kong appointment", "tagubilin para sa pagpapatuloy ng pangangalaga"],
            "Bisaya/Cebuano": ["akong plano sa pag-atiman", "paagi sa pagsangguni", "sunod nakong appointment", "instruksyon sa padayon nga pag-atiman"],
        },
    },
]


def canonical(text: str) -> str:
    value = unicodedata.normalize("NFKC", text).casefold()
    value = re.sub(r"[^\w\s]", " ", value, flags=re.UNICODE)
    return " ".join(value.split())


def sha256_file(path: Path) -> str:
    digest = hashlib.sha256()
    with path.open("rb") as handle:
        for chunk in iter(lambda: handle.read(1024 * 1024), b""):
            digest.update(chunk)
    return digest.hexdigest()


def proportional_counts(total: int, ratios: dict[str, float]) -> dict[str, int]:
    exact = {name: total * ratio for name, ratio in ratios.items()}
    result = {name: int(value) for name, value in exact.items()}
    remainder = total - sum(result.values())
    order = sorted(ratios, key=lambda name: (exact[name] - result[name], name), reverse=True)
    for name in order[:remainder]:
        result[name] += 1
    return result


def allocate_stratum_rows(
    stratum_counts: dict[tuple[str, str], int],
) -> dict[tuple[str, str], dict[str, int]]:
    base: dict[tuple[str, str], dict[str, int]] = {}
    fractional: dict[tuple[str, str], dict[str, float]] = {}
    for key, count in stratum_counts.items():
        base[key] = {
            split: int(count * ratio) for split, ratio in SPLIT_RATIOS.items()
        }
        fractional[key] = {
            split: count * ratio - base[key][split]
            for split, ratio in SPLIT_RATIOS.items()
        }
    remaining = {
        split: SPLIT_TARGETS[split] - sum(rows[split] for rows in base.values())
        for split in SPLIT_RATIOS
    }
    keys = list(stratum_counts)
    best: tuple[float, list[str]] | None = None

    def assign(index: int, deficits: dict[str, int], choices: list[str], score: float) -> None:
        nonlocal best
        if index == len(keys):
            if all(value == 0 for value in deficits.values()):
                if best is None or score > best[0]:
                    best = (score, choices.copy())
            return
        key = keys[index]
        extras = stratum_counts[key] - sum(base[key].values())
        options = sorted(
            SPLIT_RATIOS,
            key=lambda split: fractional[key][split],
            reverse=True,
        )
        for selected in combinations(options, extras):
            next_deficits = deficits.copy()
            if any(next_deficits[split] == 0 for split in selected):
                continue
            for split in selected:
                next_deficits[split] -= 1
            if any(value < 0 for value in next_deficits.values()):
                continue
            assign(
                index + 1,
                next_deficits,
                choices + [",".join(selected)],
                score + sum(fractional[key][split] for split in selected),
            )

    assign(0, remaining, [], 0.0)
    if best is None:
        raise ValueError(f"Unable to allocate exact split totals: {remaining}")
    for key, choice in zip(keys, best[1]):
        for split in filter(None, choice.split(",")):
            base[key][split] += 1
    return base


def make_family_events(
    language: str, label: str, topic: dict, action_index: int, detail_index: int
) -> tuple[str, list[dict]]:
    family_id = (
        f"{LANGUAGE_CODES[language]}-{LABEL_CODES[label]}-"
        f"{topic['id']}-A{action_index}-D{detail_index}"
    )
    template = ACTIONS[language][topic["category"]][label][action_index]
    detail = topic["details"][language][detail_index]
    action = template.format(detail=detail)
    frames = FRAMES[language][label]
    events = []
    for context_index, context in enumerate(CONTEXTS[language]):
        frame_index = (context_index + action_index + detail_index) % len(frames)
        text = frames[frame_index].format(
            actor=topic["actor"][language],
            action=action,
            context=context,
            service=topic["name"][language],
        )
        events.append(
            {
                "event_id": f"{family_id}-C{context_index}",
                "family_id": family_id,
                "topic_id": topic["id"],
                "topic_name": topic["name"][language],
                "text": text,
            }
        )
    return family_id, events


def make_stratum_rows(
    language: str,
    label: str,
    quotas: dict[str, int],
    rng: random.Random,
    counters: Counter,
) -> tuple[dict[str, list[dict]], dict[str, set[str]]]:
    families: dict[str, dict] = {}
    for topic in TOPICS:
        for action_index in range(6):
            for detail_index in range(4):
                family_id, events = make_family_events(
                    language, label, topic, action_index, detail_index
                )
                families[family_id] = {
                    "topic_id": topic["id"],
                    "topic_name": topic["name"][language],
                    "events": events,
                }

    family_keys = sorted(families)
    rng.shuffle(family_keys)
    family_quotas = proportional_counts(len(family_keys), SPLIT_RATIOS)
    family_splits: dict[str, set[str]] = {}
    cursor = 0
    for split, count in family_quotas.items():
        family_splits[split] = set(family_keys[cursor : cursor + count])
        cursor += count

    output: dict[str, list[dict]] = {split: [] for split in SPLIT_RATIOS}
    for split, family_set in family_splits.items():
        family_events = {
            family_id: families[family_id]["events"].copy()
            for family_id in sorted(family_set)
        }
        for family_id in sorted(family_events):
            rng.shuffle(family_events[family_id])
        candidates = [
            (left, right)
            for left, right in combinations(sorted(family_set), 2)
            if families[left]["topic_id"] != families[right]["topic_id"]
        ]
        rng.shuffle(candidates)
        used_event_ids: set[str] = set()
        used_family_pairs: set[tuple[str, str]] = set()
        for left, right in candidates:
            if len(output[split]) >= quotas[split]:
                break
            if not family_events[left] or not family_events[right]:
                continue
            first = family_events[left].pop()
            second = family_events[right].pop()
            if first["event_id"] in used_event_ids or second["event_id"] in used_event_ids:
                raise ValueError("An event was selected more than once.")
            used_event_ids.update((first["event_id"], second["event_id"]))
            family_pair = tuple(sorted((left, right)))
            if family_pair in used_family_pairs:
                raise ValueError("A semantic family pair was selected more than once.")
            used_family_pairs.add(family_pair)
            if rng.randrange(2):
                first, second = second, first

            counters[(language, label)] += 1
            record_id = (
                f"SFMSV3-{LANGUAGE_CODES[language]}-{LABEL_CODES[label]}-"
                f"{counters[(language, label)]:04d}"
            )
            output[split].append(
                {
                    "record_id": record_id,
                    "text": f"{first['text']} {second['text']}",
                    "label": label,
                    "language_group": language,
                    "topic": f"{first['topic_name']} / {second['topic_name']}",
                    "source_group": "generated_synthetic_v3",
                    "duplicate_group": f"PAIR:{family_pair[0]}+{family_pair[1]}",
                    "annotator": "Synthetic-Generated-v3",
                    "review_status": "Pending",
                    "_families": list(family_pair),
                    "_events": [first["event_id"], second["event_id"]],
                }
            )
        if len(output[split]) != quotas[split]:
            raise ValueError(
                f"Could not meet {language}/{label}/{split} quota "
                f"({len(output[split])}/{quotas[split]})."
            )
    return output, family_splits


def event_shingles(text: str) -> set[str]:
    words = canonical(text).split()
    return {" ".join(words[index : index + 3]) for index in range(len(words) - 2)}


def validate_rows(
    rows_by_split: dict[str, list[dict]],
    expected_strata: dict[tuple[str, str], int],
    original_rows: list[dict],
) -> dict:
    all_rows = [row for rows in rows_by_split.values() for row in rows]
    errors: list[str] = []
    if len(all_rows) != 5000:
        errors.append(f"expected 5000 rows; found {len(all_rows)}")
    if {split: len(rows) for split, rows in rows_by_split.items()} != SPLIT_TARGETS:
        errors.append("split totals do not match 3500/750/750")

    ids = [row["record_id"] for row in all_rows]
    texts = [canonical(row["text"]) for row in all_rows]
    if len(ids) != len(set(ids)):
        errors.append("record IDs are not unique")
    if len(texts) != len(set(texts)):
        errors.append("normalized full-text duplicates found")
    if any(not value for row in all_rows for value in row.values() if not value):
        errors.append("one or more required fields are empty")
    if any(row["source_group"] != "generated_synthetic_v3" for row in all_rows):
        errors.append("a row is missing synthetic provenance")
    if any(row["review_status"] != "Pending" for row in all_rows):
        errors.append("generated records must remain pending human review")

    language_counts = Counter(row["language_group"] for row in all_rows)
    if dict(language_counts) != LANGUAGE_TARGETS:
        errors.append(f"language distribution mismatch: {dict(language_counts)}")
    sentiment_counts = Counter(row["label"] for row in all_rows)
    expected_sentiments = {
        label: sum(LANGUAGE_LABEL_TARGETS[language][label] for language in LANGUAGES)
        for label in LABELS
    }
    if dict(sentiment_counts) != expected_sentiments:
        errors.append(f"sentiment distribution mismatch: {dict(sentiment_counts)}")

    per_language_sentiment = {
        language: dict(Counter(row["label"] for row in all_rows if row["language_group"] == language))
        for language in LANGUAGES
    }
    if per_language_sentiment != LANGUAGE_LABEL_TARGETS:
        errors.append(f"per-language sentiment mismatch: {per_language_sentiment}")

    split_by_id = {
        row["record_id"]: split
        for split, split_rows in rows_by_split.items()
        for row in split_rows
    }
    family_owners: dict[str, set[str]] = defaultdict(set)
    family_pair_counts = Counter()
    event_counts = Counter()
    for split, split_rows in rows_by_split.items():
        for row in split_rows:
            for family in row["_families"]:
                family_owners[family].add(split)
            family_pair_counts[row["duplicate_group"]] += 1
            event_counts.update(row["_events"])
    cross_split_families = sum(1 for owners in family_owners.values() if len(owners) > 1)
    if cross_split_families:
        errors.append(f"{cross_split_families} semantic families cross splits")
    if any(count != 1 for count in family_pair_counts.values()):
        errors.append("semantic family pairs are repeated")
    reused_events = sum(1 for count in event_counts.values() if count != 1)
    if reused_events:
        errors.append(f"{reused_events} generated event sentences were reused")

    stratum_split_counts = {}
    for language in LANGUAGES:
        for label in LABELS:
            key = (language, label)
            observed = {
                split: sum(
                    1
                    for row in split_rows
                    if row["language_group"] == language and row["label"] == label
                )
                for split, split_rows in rows_by_split.items()
            }
            if sum(observed.values()) != expected_strata[key]:
                errors.append(f"stratum total mismatch for {language}/{label}")
            stratum_split_counts[f"{language}/{label}"] = observed

    old_texts = {canonical(row.get("text", "")) for row in original_rows}
    exact_old_overlap = sum(1 for text in texts if text in old_texts)
    if exact_old_overlap:
        errors.append(f"{exact_old_overlap} new rows exactly overlap the preserved 900-row master")

    # Compare complete feedback records that share a generated semantic family.
    by_family: dict[str, list[int]] = defaultdict(list)
    for index, row in enumerate(all_rows):
        for family in row["_families"]:
            by_family[family].append(index)
    checked_near_pairs = 0
    near_duplicate_pairs = 0
    max_shared_family_similarity = 0.0
    shingle_cache = [event_shingles(row["text"]) for row in all_rows]
    for indices in by_family.values():
        for left, right in combinations(indices, 2):
            checked_near_pairs += 1
            similarity = SequenceMatcher(
                None, texts[left], texts[right], autojunk=False
            ).ratio()
            max_shared_family_similarity = max(max_shared_family_similarity, similarity)
            if similarity >= SIMILARITY_THRESHOLD:
                near_duplicate_pairs += 1
    if near_duplicate_pairs:
        errors.append(
            f"{near_duplicate_pairs} near-duplicate full-text pairs meet "
            f"the {SIMILARITY_THRESHOLD:.2f} similarity threshold"
        )

    topic_counts = Counter()
    for row in all_rows:
        for topic in row["topic"].split(" / "):
            topic_counts[topic] += 1

    result = {
        "passed": not errors,
        "errors": errors,
        "total_samples": len(all_rows),
        "language_counts": dict(language_counts),
        "sentiment_counts": dict(sentiment_counts),
        "per_language_sentiment": per_language_sentiment,
        "split_counts": {split: len(rows) for split, rows in rows_by_split.items()},
        "split_language_sentiment_counts": stratum_split_counts,
        "topic_mentions": dict(sorted(topic_counts.items())),
        "record_ids_unique": len(ids) == len(set(ids)),
        "normalized_text_duplicates": len(texts) - len(set(texts)),
        "exact_overlap_with_original_900": exact_old_overlap,
        "semantic_families_cross_splits": cross_split_families,
        "reused_event_sentences": reused_events,
        "repeated_semantic_family_pairs": sum(
            1 for count in family_pair_counts.values() if count > 1
        ),
        "near_duplicate_check": {
            "method": "SequenceMatcher ratio on complete records sharing a semantic family",
            "threshold": SIMILARITY_THRESHOLD,
            "pairs_checked": checked_near_pairs,
            "pairs_at_or_above_threshold": near_duplicate_pairs,
            "maximum_similarity": round(max_shared_family_similarity, 4),
        },
    }
    if errors:
        raise ValueError(json.dumps(result, indent=2, ensure_ascii=False))
    return result


def load_original() -> tuple[list[dict], str]:
    if not ORIGINAL.exists():
        raise FileNotFoundError(f"Preserved original dataset not found: {ORIGINAL}")
    before_hash = sha256_file(ORIGINAL)
    with ORIGINAL.open("r", encoding="utf-8-sig", newline="") as handle:
        rows = list(csv.DictReader(handle))
    if len(rows) != 900:
        raise ValueError(f"Expected the preserved master to have 900 rows, found {len(rows)}")
    return rows, before_hash


def build_dataset() -> tuple[dict[str, list[dict]], dict]:
    rng = random.Random(SEED)
    strata_counts = {
        (language, label): LANGUAGE_LABEL_TARGETS[language][label]
        for language in LANGUAGES
        for label in LABELS
    }
    split_quotas = allocate_stratum_rows(strata_counts)
    counters: Counter = Counter()
    rows_by_split = {split: [] for split in SPLIT_RATIOS}
    family_owners: dict[str, set[str]] = defaultdict(set)

    for language in LANGUAGES:
        for label in LABELS:
            key = (language, label)
            stratum_rows, family_splits = make_stratum_rows(
                language, label, split_quotas[key], rng, counters
            )
            for split, rows in stratum_rows.items():
                rows_by_split[split].extend(rows)
                for family in family_splits[split]:
                    family_owners[family].add(split)

    for rows in rows_by_split.values():
        rng.shuffle(rows)
    return rows_by_split, {
        "stratum_counts": {f"{lang}/{label}": count for (lang, label), count in strata_counts.items()},
        "stratum_split_targets": {
            f"{lang}/{label}": split_quotas[(lang, label)]
            for lang in LANGUAGES
            for label in LABELS
        },
        "family_split_count": {
            split: sum(1 for owners in family_owners.values() if owners == {split})
            for split in SPLIT_RATIOS
        },
    }


def write_csv_new(path: Path, rows: list[dict]) -> None:
    with path.open("x", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=FIELDS)
        writer.writeheader()
        writer.writerows({key: row[key] for key in FIELDS} for row in rows)


def render_validation(summary: dict, outputs: dict[str, str]) -> str:
    lines = [
        "# SFMS multilingual sentiment dataset v3 validation",
        "",
        "## Result",
        "",
        f"- Status: **{'PASS' if summary['passed'] else 'FAIL'}**",
        f"- Total: **{summary['total_samples']:,}** synthetic feedback samples",
        "- Provenance: every record is marked `generated_synthetic_v3`; none is represented as real patient feedback.",
        "- Human review: every record is `Pending`; this report does not claim native-speaker or clinician review.",
        "",
        "## Distributions",
        "",
        "| Language | Samples | Positive | Negative | Neutral |",
        "|---|---:|---:|---:|---:|",
    ]
    for language in LANGUAGES:
        counts = summary["per_language_sentiment"][language]
        lines.append(
            f"| {language} | {sum(counts.values())} | {counts['Positive']} | "
            f"{counts['Negative']} | {counts['Neutral']} |"
        )
    lines.extend(["", "| Split | Samples |", "|---|---:|"])
    for split, count in summary["split_counts"].items():
        lines.append(f"| {split.title()} | {count} |")
    lines.extend(
        [
            "",
            "## Validation checks",
            "",
            f"- Unique record IDs: **{'PASS' if summary['record_ids_unique'] else 'FAIL'}**.",
            f"- Exact normalized text duplicates: **{summary['normalized_text_duplicates']}**.",
            f"- Exact overlap with the preserved original 900-row master: **{summary['exact_overlap_with_original_900']}**.",
            f"- Semantic families shared across splits: **{summary['semantic_families_cross_splits']}**.",
            f"- Reused event sentences: **{summary['reused_event_sentences']}**.",
            f"- Repeated semantic family pairs: **{summary['repeated_semantic_family_pairs']}**.",
            f"- Near-duplicate screen: {summary['near_duplicate_check']['pairs_checked']} complete-record pairs sharing a semantic family checked; "
            f"{summary['near_duplicate_check']['pairs_at_or_above_threshold']} at or above "
            f"{summary['near_duplicate_check']['threshold']:.2f} SequenceMatcher similarity.",
            "",
            "The split builder assigns entire semantic families to a single split before pairing distinct, unused event sentences. "
            "This prevents a generated action/detail family from appearing across train, validation, and test. "
            "The lexical near-duplicate screen is not a substitute for human semantic review.",
            "",
            "## Output files",
            "",
        ]
    )
    for name, digest in outputs.items():
        lines.append(f"- `{name}` — SHA-256 `{digest}`")
    lines.extend(
        [
            "",
            "## Limitations",
            "",
            "All records are controlled synthetic compositions based on language-specific, sentiment-specific phrase banks. "
            "They are not downloaded, translated from an external corpus, or collected from actual patients. "
            "Template structure and phrase reuse may make the corpus less linguistically diverse than naturally occurring feedback. "
            "Tagalog and Cebuano wording, sentiment nuance, and topic fit require independent native-speaker review before model training or research claims.",
            "",
        ]
    )
    return "\n".join(lines)


def main() -> int:
    parser = argparse.ArgumentParser(description="Build the synthetic SFMS v3 5,000-row corpus.")
    parser.add_argument("--dry-run", action="store_true", help="Generate and validate in memory without writing files.")
    args = parser.parse_args()

    output_paths = [
        MASTER_OUTPUT,
        SPLIT_DIR / "train.csv",
        SPLIT_DIR / "validation.csv",
        SPLIT_DIR / "test.csv",
        SUMMARY_OUTPUT,
        VALIDATION_OUTPUT,
    ]
    existing = [str(path) for path in output_paths if path.exists()]
    if existing and not args.dry_run:
        raise FileExistsError(
            "Refusing to overwrite versioned outputs: " + ", ".join(existing)
        )

    original_rows, original_hash_before = load_original()
    rows_by_split, allocation = build_dataset()
    stratum_counts = {
        (language, label): LANGUAGE_LABEL_TARGETS[language][label]
        for language in LANGUAGES
        for label in LABELS
    }
    summary = validate_rows(rows_by_split, stratum_counts, original_rows)
    summary.update(
        {
            "dataset_name": "SFMS Multilingual Healthcare Sentiment Dataset v3",
            "version": "3.0",
            "seed": SEED,
            "provenance": "developer-generated synthetic examples; no external source",
            "source_group": "generated_synthetic_v3",
            "review_status": "Pending",
            "split_ratios": SPLIT_RATIOS,
            "split_allocation": allocation,
            "split_targets_by_stratum": {
                f"{language}/{label}": split_quotas
                for (language, label), split_quotas in allocate_stratum_rows(stratum_counts).items()
            },
            "original_master_sha256_before": original_hash_before,
            "outputs": {
                "master": str(MASTER_OUTPUT.relative_to(ROOT)),
                "train": str((SPLIT_DIR / "train.csv").relative_to(ROOT)),
                "validation": str((SPLIT_DIR / "validation.csv").relative_to(ROOT)),
                "test": str((SPLIT_DIR / "test.csv").relative_to(ROOT)),
            },
        }
    )

    if args.dry_run:
        print(json.dumps(summary, indent=2, ensure_ascii=False))
        return 0

    SPLIT_DIR.mkdir(parents=True, exist_ok=True)
    MASTER_OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    SUMMARY_OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    write_csv_new(
        MASTER_OUTPUT,
        [row for split in SPLIT_RATIOS for row in rows_by_split[split]],
    )
    for split in SPLIT_RATIOS:
        write_csv_new(SPLIT_DIR / f"{split}.csv", rows_by_split[split])

    original_hash_after = sha256_file(ORIGINAL)
    if original_hash_after != original_hash_before:
        raise RuntimeError("The original 900-row master changed during generation.")

    output_hashes = {
        str(path.relative_to(ROOT)): sha256_file(path)
        for path in [
            MASTER_OUTPUT,
            SPLIT_DIR / "train.csv",
            SPLIT_DIR / "validation.csv",
            SPLIT_DIR / "test.csv",
        ]
    }
    summary["original_master_sha256_after"] = original_hash_after
    summary["outputs_sha256"] = output_hashes
    SUMMARY_OUTPUT.write_text(
        json.dumps(summary, indent=2, ensure_ascii=False) + "\n",
        encoding="utf-8",
    )
    VALIDATION_OUTPUT.write_text(
        render_validation(summary, output_hashes),
        encoding="utf-8",
    )
    print(json.dumps(summary, indent=2, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
