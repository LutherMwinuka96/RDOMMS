"""Generate RDOMMS project objectives and research questions Word document."""
from pathlib import Path

from docx import Document
from docx.enum.text import WD_ALIGN_PARAGRAPH
from docx.shared import Pt
from docx.oxml.ns import qn


def set_document_fonts(document, font_name="Times New Roman", font_size=12):
    style = document.styles["Normal"]
    style.font.name = font_name
    style.font.size = Pt(font_size)
    style._element.rPr.rFonts.set(qn("w:eastAsia"), font_name)


def add_paragraph(document, text, italic=False, spacing_after=6):
    paragraph = document.add_paragraph()
    run = paragraph.add_run(text)
    run.italic = italic
    run.font.name = "Times New Roman"
    run.font.size = Pt(12)
    paragraph.paragraph_format.space_after = Pt(spacing_after)
    return paragraph


def add_numbered_item(document, text):
    paragraph = document.add_paragraph(style="List Number")
    run = paragraph.add_run(text)
    run.font.name = "Times New Roman"
    run.font.size = Pt(12)
    paragraph.paragraph_format.space_after = Pt(8)


def build_document():
    document = Document()
    set_document_fonts(document)

    title = document.add_heading("RDO|MMS — Project Objectives and Research Questions", level=0)
    title.alignment = WD_ALIGN_PARAGRAPH.CENTER
    for run in title.runs:
        run.font.name = "Times New Roman"

    add_paragraph(
        document,
        "Rural Development Organization Monitoring and Management System (RDO|MMS)",
        italic=True,
        spacing_after=12,
    )

    document.add_heading("General Objective", level=1)
    add_paragraph(
        document,
        "To assess current monitoring, management, and reporting workflows among rural "
        "development organizations in the Iringa Region; design and develop a user-centered, "
        "secure, and scalable web-based monitoring and management system (RDO|MMS) with "
        "integrated modules for beneficiaries, projects, planning, finance, collaboration, "
        "and reporting; and evaluate its functionality, usability, and organizational impact "
        "through structured testing with partner organizations — in order to improve operational "
        "efficiency, transparency, and coordination.",
    )

    document.add_heading("Specific Objectives", level=1)
    objectives = [
        "To assess the current monitoring, management, and reporting workflows used by rural "
        "development organizations in Iringa Region, identifying operational gaps, data "
        "management challenges, and user requirements that inform system design.",
        "To design a user-centered web-based system architecture that supports secure data "
        "collection, centralized storage, role-based access control, dashboard visualization, "
        "and responsive access on desktop and mobile devices for rural development organizations.",
        "To develop a functional prototype of RDO|MMS with core modules for beneficiary and "
        "project management, planning and progress monitoring, financial tracking (budget "
        "allocation and expense management), internal collaboration (notifications, messaging, "
        "and approvals), and report generation with export capabilities.",
        "To evaluate the system's practical functionality, usability, adaptability to "
        "organizational workflows, and contribution to operational improvements through "
        "structured testing with partner organizations in Iringa Region, including functional "
        "testing, user acceptance testing (UAT), and feedback-based refinement.",
    ]
    for objective in objectives:
        add_numbered_item(document, objective)

    document.add_heading("Research Questions", level=1)
    questions = [
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
    for question in questions:
        add_numbered_item(document, question)

    return document


def main():
    output_dir = Path(__file__).resolve().parents[1] / "docs"
    output_dir.mkdir(exist_ok=True)
    output_path = output_dir / "RDOMMS-Project-Objectives.docx"
    build_document().save(output_path)
    print(f"Created: {output_path}")


if __name__ == "__main__":
    main()
