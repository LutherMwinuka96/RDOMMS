"""Update FULL PROPOSAL.docx to align with implemented RDOMMS system."""
from __future__ import annotations

import shutil
from copy import deepcopy
from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.oxml import OxmlElement
from docx.oxml.ns import qn
from docx.shared import Inches, Pt

ROOT = Path(__file__).resolve().parents[1]
DOC_PATH = ROOT / "docs" / "FULL PROPOSAL.docx"
BACKUP_PATH = ROOT / "docs" / "FULL PROPOSAL.backup.docx"
DIAGRAMS = ROOT / "docs" / "diagrams"

UML_IMAGE_MAP = {
    "rId17": "use-case-diagram.png",
    "rId18": "activity-diagram.png",
    "rId19": "sequence-diagram.png",
    "rId20": "class-diagram.png",
    "rId21": "deployment-diagram.png",
    "rId22": "erd-diagram.png",
}

RESEARCH_QUESTIONS = [
    (
        "RQ1 (Objective 1): What are the current monitoring, management, and reporting "
        "workflows used by rural development organizations in the Iringa Region, and what "
        "operational gaps and system requirements do they reveal?"
    ),
    (
        "RQ2 (Objective 2): What user-centered web-based system architecture is suitable "
        "for supporting secure data collection, role-based access control, dashboard "
        "visualization, and responsive use across desktop and mobile devices for rural "
        "development organizations?"
    ),
    (
        "RQ3 (Objective 3): How can a functional RDO|MMS prototype be developed with "
        "integrated modules for beneficiary and project management, planning and progress "
        "monitoring, financial tracking, internal collaboration, and report generation?"
    ),
    (
        "RQ4 (Objective 4): To what extent is RDO|MMS functional, usable, adaptable to "
        "organizational workflows, and effective in improving operational efficiency, "
        "transparency, and coordination during structured testing with partner organizations?"
    ),
]

TABLE_SPECS = [
    {
        "title": "1. users",
        "description": (
            "The users table stores authentication credentials, profile information, and "
            "role assignments for all system users including administrators, managers, staff, "
            "and finance officers."
        ),
        "rows": [
            ("id", "INT (PK, AUTO_INCREMENT)", "Internal primary key"),
            ("user_id", "VARCHAR(50), UNIQUE", "Business identifier for the user"),
            ("fullname", "VARCHAR(100)", "Full name of the user"),
            ("email", "VARCHAR(100), UNIQUE", "Login email address"),
            ("password", "VARCHAR(255)", "Hashed password"),
            ("role", "VARCHAR(50)", "Assigned system role for RBAC"),
            ("department", "VARCHAR(100)", "Department affiliation"),
            ("organization", "VARCHAR(100)", "Organization name"),
            ("status", "ENUM(active,pending,suspended)", "Account approval state"),
            ("profile_picture", "TEXT", "Path to uploaded profile image"),
            ("created_at / updated_at", "TIMESTAMP", "Audit timestamps"),
        ],
    },
    {
        "title": "2. beneficiaries",
        "description": (
            "The beneficiaries table stores demographic and location details for individuals "
            "and groups receiving rural development services."
        ),
        "rows": [
            ("id", "INT (PK, AUTO_INCREMENT)", "Internal primary key"),
            ("beneficiary_id", "VARCHAR(50), UNIQUE", "Business identifier"),
            ("fullname", "VARCHAR(100)", "Beneficiary name"),
            ("gender", "ENUM(male,female,other)", "Gender"),
            ("category", "VARCHAR(100)", "Beneficiary category"),
            ("district / ward / location", "VARCHAR", "Geographic details"),
            ("status", "ENUM(active,inactive,pending)", "Registration status"),
            ("registered_by", "INT (FK -> users.id)", "Officer who registered record"),
            ("registered_date", "DATE", "Date of registration"),
            ("created_at / updated_at", "TIMESTAMP", "Audit timestamps"),
        ],
    },
    {
        "title": "3. projects",
        "description": (
            "The projects table supports project lifecycle tracking including budget, progress, "
            "location, and implementation status."
        ),
        "rows": [
            ("id", "INT (PK, AUTO_INCREMENT)", "Internal primary key"),
            ("project_id", "VARCHAR(50), UNIQUE", "Business identifier"),
            ("name", "VARCHAR(200)", "Project title"),
            ("description", "TEXT", "Project summary"),
            ("district / sector", "VARCHAR", "Implementation context"),
            ("budget", "DECIMAL(15,2)", "Approved project budget"),
            ("progress", "INT", "Completion percentage"),
            ("status", "ENUM(not-started,ongoing,delayed,completed)", "Project state"),
            ("created_by", "INT (FK -> users.id)", "Project creator"),
            ("start_date / end_date", "DATE", "Project timeline"),
        ],
    },
    {
        "title": "4. budget_allocations",
        "description": (
            "The budget_allocations table records departmental budget allocations by quarter "
            "and fiscal year, including approval workflow."
        ),
        "rows": [
            ("id", "INT (PK, AUTO_INCREMENT)", "Internal primary key"),
            ("allocation_id", "VARCHAR(50), UNIQUE", "Business identifier"),
            ("department", "VARCHAR(100)", "Receiving department"),
            ("quarter", "ENUM(q1,q2,q3,q4)", "Budget quarter"),
            ("fiscal_year", "YEAR", "Financial year"),
            ("amount", "DECIMAL(15,2)", "Allocated amount"),
            ("status", "ENUM(pending,approved,rejected)", "Approval status"),
            ("created_by", "INT", "User who created allocation"),
            ("approved_by", "INT", "User who approved allocation"),
            ("created_at / updated_at", "TIMESTAMP", "Audit timestamps"),
        ],
    },
    {
        "title": "5. expenses",
        "description": (
            "The expenses table tracks actual organizational spending against departments, "
            "categories, and fiscal periods."
        ),
        "rows": [
            ("id", "INT (PK, AUTO_INCREMENT)", "Internal primary key"),
            ("expense_id", "VARCHAR(50), UNIQUE", "Business identifier"),
            ("expense_date", "DATE", "Date expense occurred"),
            ("category", "VARCHAR(50)", "Expense category"),
            ("department", "VARCHAR(100)", "Responsible department"),
            ("amount", "DECIMAL(15,2)", "Expense amount"),
            ("status", "ENUM(pending,approved,rejected)", "Approval status"),
            ("created_by", "INT (FK -> users.id)", "User who recorded expense"),
            ("reference", "VARCHAR(100)", "Receipt or voucher reference"),
            ("created_at", "TIMESTAMP", "Record creation time"),
        ],
    },
]


def set_paragraph_text(paragraph, text: str) -> None:
    for run in paragraph.runs:
        run.text = ""
    if paragraph.runs:
        paragraph.runs[0].text = text
    else:
        paragraph.add_run(text)


def replace_paragraph_contains(doc: Document, needle: str, new_text: str) -> bool:
    for paragraph in doc.paragraphs:
        if needle in paragraph.text:
            set_paragraph_text(paragraph, new_text)
            return True
    return False


def replace_image_by_rid(doc: Document, rid: str, image_path: Path) -> None:
    part = doc.part.related_parts[rid]
    part._blob = image_path.read_bytes()


def delete_paragraph(paragraph) -> None:
    element = paragraph._element
    element.getparent().remove(element)
    paragraph._p = paragraph._element = None


def make_table_element(doc: Document, headers, rows):
    table = doc.add_table(rows=1, cols=len(headers))
    table.style = "Table Grid"
    hdr = table.rows[0].cells
    for idx, header in enumerate(headers):
        hdr[idx].text = header
        for run in hdr[idx].paragraphs[0].runs:
            run.bold = True
            run.font.size = Pt(9)
    for row_data in rows:
        cells = table.add_row().cells
        for idx, value in enumerate(row_data):
            cells[idx].text = value
            for run in cells[idx].paragraphs[0].runs:
                run.font.size = Pt(9)
    tbl = table._tbl
    tbl.getparent().remove(tbl)
    return tbl


def insert_block_before(paragraph, elements) -> None:
    anchor = paragraph._p
    for element in reversed(elements):
        anchor.addprevious(element)


def rebuild_table_design_section(doc: Document) -> None:
    start_idx = None
    end_idx = None
    for i, paragraph in enumerate(doc.paragraphs):
        text = paragraph.text.strip()
        if text.startswith("1. Users Table") or text == "1. users":
            start_idx = i
        if text.startswith("3.5.2 Normalization"):
            end_idx = i
            break
    if start_idx is None or end_idx is None:
        if any(p.text.strip() == "1. users" for p in doc.paragraphs):
            return
        raise RuntimeError("Could not locate table design section")
    if doc.paragraphs[start_idx].text.strip() == "1. users":
        return

    intro = (
        "The database schema is implemented in MySQL (rdomms_db). Five core tables that "
        "support authentication, beneficiary management, project tracking, and financial "
        "monitoring are summarized below. The complete schema is represented in Figure 10."
    )
    replace_paragraph_contains(doc, "The database schema is implemented in MySQL.", intro)

    normalization_para = doc.paragraphs[end_idx]
    block = []
    for spec in TABLE_SPECS:
        title_p = doc.add_paragraph()
        title_p.add_run(spec["title"]).bold = True
        block.append(title_p._p)
        title_p._p.getparent().remove(title_p._p)

        desc_p = doc.add_paragraph(spec["description"])
        block.append(desc_p._p)
        desc_p._p.getparent().remove(desc_p._p)

        block.append(make_table_element(doc, ["Field", "Data Type", "Description"], spec["rows"]))

        spacer = OxmlElement("w:p")
        block.append(spacer)

    insert_block_before(normalization_para, block)

    for i in range(end_idx - 1, start_idx - 1, -1):
        delete_paragraph(doc.paragraphs[i])


def update_uat_table(doc: Document) -> None:
    table = doc.tables[3]
    rows = [
        (
            "User Login",
            "Staff enters valid email and password on sign-in page",
            "Session is created and dashboard loads with role-based menu",
        ),
        (
            "Beneficiary Registration",
            "Field officer submits beneficiary registration form",
            "Beneficiary record is saved and appears in beneficiary list",
        ),
        (
            "Budget Allocation",
            "Finance officer creates quarterly budget allocation",
            "Allocation is stored with pending/approved status",
        ),
        (
            "Report Export",
            "Manager generates financial/progress report",
            "Report preview loads and exports to PDF or Excel",
        ),
        (
            "Access Control",
            "Staff attempts restricted finance module",
            "RBAC denies unauthorized access and allows permitted roles",
        ),
    ]
    table.rows[0].cells[0].text = "Test Case"
    table.rows[0].cells[1].text = "Scenario"
    table.rows[0].cells[2].text = "Expected Outcome"
    while len(table.rows) > 1:
        table._tbl.remove(table.rows[-1]._tr)
    for row_data in rows:
        cells = table.add_row().cells
        for col, value in enumerate(row_data):
            cells[col].text = value


def update_document() -> None:
    if not DOC_PATH.exists():
        raise FileNotFoundError(DOC_PATH)
    shutil.copy2(DOC_PATH, BACKUP_PATH)

    doc = Document(str(DOC_PATH))

    # General and specific objectives formatting
    for paragraph in doc.paragraphs:
        text = paragraph.text.strip()
        if text.startswith("GeneralObjective"):
            set_paragraph_text(
                paragraph,
                "General Objective\n"
                "To assess current monitoring, management, and reporting workflows among rural "
                "development organizations in the Iringa Region; design and develop a user-centered, "
                "secure, and scalable web-based monitoring and management system (RDO|MMS) with "
                "integrated modules for beneficiaries, projects, planning, finance, collaboration, "
                "and reporting; and evaluate its functionality, usability, and organizational impact "
                "through structured testing with partner organizations — in order to improve "
                "operational efficiency, transparency, and coordination.",
            )
        elif text == "Specific Objectives":
            set_paragraph_text(paragraph, "Specific Objectives")
        elif text.startswith("1.To assess the current monitoring"):
            set_paragraph_text(
                paragraph,
                "1. To assess the current monitoring, management, and reporting workflows used by "
                "rural development organizations in Iringa Region, identifying operational gaps, "
                "data management challenges, and user requirements that inform system design.",
            )
        elif text.startswith("2.To design a user-centered"):
            set_paragraph_text(
                paragraph,
                "2. To design a user-centered web-based system architecture that supports secure "
                "data collection, centralized storage, role-based access control, dashboard "
                "visualization, and responsive access on desktop and mobile devices for rural "
                "development organizations.",
            )
        elif text.startswith("3.To develop a functional prototype"):
            set_paragraph_text(
                paragraph,
                "3. To develop a functional prototype of RDO|MMS with core modules for beneficiary "
                "and project management, planning and progress monitoring, financial tracking "
                "(budget allocation and expense management), internal collaboration (notifications, "
                "messaging, and approvals), and report generation with export capabilities.",
            )
        elif text.startswith("4.To evaluate"):
            set_paragraph_text(
                paragraph,
                "4. To evaluate the system's practical functionality, usability, adaptability to "
                "organizational workflows, and contribution to operational improvements through "
                "structured testing with partner organizations in Iringa Region, including "
                "functional testing, user acceptance testing (UAT), and feedback-based refinement.",
            )

    rq_paragraphs = []
    capture = False
    for paragraph in doc.paragraphs:
        stripped = paragraph.text.strip()
        if stripped == "1.5 Research Questions":
            capture = True
            continue
        if capture:
            if stripped.startswith("1.6 Significance"):
                break
            if stripped[:1].isdigit() and "." in stripped[:3]:
                rq_paragraphs.append(paragraph)

    for paragraph, question in zip(rq_paragraphs[:4], RESEARCH_QUESTIONS):
        set_paragraph_text(paragraph, question)

    replacements = {
        "incorporating offline functionality": "incorporating secure web-based architecture and responsive access",
        "Design and implementation of a scalable": "design, development, and evaluation of a scalable",
        "Web-Based System\u00a0is defined as a software application accessed through a web browser that stores data on a central server, while incorporating offline": "Web-Based System is defined as a software application accessed through a web browser that stores data on a central MySQL server and is delivered through PHP REST APIs with responsive access on desktop and mobile devices, while supporting",
        "Offline Functionality\u00a0refers to the system's ability to maintain complete operational capability including data entry, form submission, and data storage without an active internet connection": "Responsive Web Access refers to the system's ability to provide usable interfaces on phones, tablets, and computers through a mobile-friendly web design, allowing staff to access monitoring tools wherever internet connectivity is available",
        "1. Limited Internet Connectivity:\u00a0Persistent unstable connectivity in Iringa's remote areas impacts system accessibility. This is addressed through ro": "1. Limited Internet Connectivity: Persistent unstable connectivity in Iringa's remote areas impacts system accessibility. This is addressed through responsive web design, lightweight pages, and low-bandwidth-friendly forms rather than full offline data synchronization",
        "2. Offline-First Design:\u00a0Guaranteeing all critical functionalities without active internet connection.": "2. Responsive Web Design: Providing mobile-friendly access to core modules through browser-based interfaces optimized for field and office use.",
        "Field staff shall update activity progress in real-time (ongoing, completed, delayed).": "Field staff shall update activity progress through the progress monitoring module (ongoing, completed, delayed).",
        "The system shall automatically generate routine reports (weekly, monthly, quarterly, donor-specific).": "The system shall generate reports on demand for progress, finance, and beneficiary modules, with support for scheduled report configuration and export.",
        "Reliability & Offline Capability: The system must work without internet. All core data entry functions shall remain fully available for up to\u00a07 days o": "Reliability & Availability: The system must remain stable during normal online operation. Core transactions such as login, form submission, and report export shall complete within acceptable response time under typical WAMP/LAMP deployment conditions.",
        'For example, the "Data Synchronization" sequence details': 'For example, the "User Login and Dashboard Access" sequence details',
        "in our\u00a0staff\u00a0table, if we had co": "in the users table, if we stored department name redundantly while also storing department_id, that would create a transitive dependency. Instead, department is stored directly for operational reporting while related organizational entities remain normalized",
        "Offline Data Sync": "Beneficiary Registration",
        "Field officer completes beneficiary registration i": "Field officer submits beneficiary registration form with valid details",
        "Data automatically synchronizes upon connection; a": "Beneficiary record is saved in MySQL and displayed in the beneficiary list",
        "Application forcibly closed during synchronization": "User submits expense record without approval permission",
        "System recovers gracefully; all data remains intac": "System rejects unauthorized action and preserves existing finance records",
    }

    for paragraph in doc.paragraphs:
        original = paragraph.text
        updated = original
        for old, new in replacements.items():
            if old in updated:
                updated = updated.replace(old, new)
        if updated != original:
            set_paragraph_text(paragraph, updated)

    replace_paragraph_contains(
        doc,
        "A comprehensive use case diagram maps all actors",
        "A comprehensive use case diagram maps actors such as Guest, Staff/Field Officer, Accountant, Department Manager, Executive Director, and System Administrator to high-level system functions including authentication, beneficiary management, project tracking, planning, finance, collaboration, and reporting.",
    )
    replace_paragraph_contains(
        doc,
        "Activity diagrams model complex business processes",
        "Activity diagrams model complex business processes by illustrating the dynamic flow of control from one activity to another. This section presents the beneficiary registration and service tracking workflow implemented in RDO|MMS.",
    )
    replace_paragraph_contains(
        doc,
        "Sequence diagrams specify the real-time interaction",
        "Sequence diagrams specify the interaction between system components for critical operations. The implemented login sequence shows how the browser, authentication API, session store, and dashboard cooperate during user access.",
    )
    replace_paragraph_contains(
        doc,
        "This static structure diagram illustrates the fundamental entities",
        "This static structure diagram illustrates the core domain entities implemented in RDO|MMS, including User, Role, Session, Beneficiary, Project, Plan, BudgetAllocation, Expense, Report, Notification, and the shared Database access layer.",
    )

    rebuild_table_design_section(doc)

    for rid, filename in UML_IMAGE_MAP.items():
        image_path = DIAGRAMS / filename
        if not image_path.exists():
            raise FileNotFoundError(image_path)
        replace_image_by_rid(doc, rid, image_path)

    update_uat_table(doc)

    doc.save(str(DOC_PATH))
    print(f"Updated: {DOC_PATH}")
    print(f"Backup:  {BACKUP_PATH}")


if __name__ == "__main__":
    update_document()
