"""Render PlantUML diagrams and inject PNGs into FULL PROPOSAL.docx."""
from __future__ import annotations

import subprocess
from pathlib import Path

from docx import Document

ROOT = Path(__file__).resolve().parents[1]
DIAGRAMS = ROOT / "docs" / "diagrams"
PLANTUML_JAR = ROOT / "scripts" / "plantuml.jar"
DOC_PATH = ROOT / "docs" / "FULL PROPOSAL.docx"

UML_IMAGE_MAP = {
    "rId17": "use-case-diagram.png",
    "rId18": "activity-diagram.png",
    "rId19": "sequence-diagram.png",
    "rId20": "class-diagram.png",
    "rId21": "deployment-diagram.png",
    "rId22": "erd-diagram.png",
}


def render_diagrams() -> None:
    if not PLANTUML_JAR.exists():
        raise FileNotFoundError(
            f"PlantUML jar not found at {PLANTUML_JAR}. Download it before rendering."
        )
    subprocess.run(
        [
            "java",
            "-jar",
            str(PLANTUML_JAR),
            "-tpng",
            "-charset",
            "UTF-8",
            str(DIAGRAMS / "*.puml"),
        ],
        check=True,
        cwd=str(DIAGRAMS),
    )


def replace_image_by_rid(doc: Document, rid: str, image_path: Path) -> None:
    part = doc.part.related_parts[rid]
    part._blob = image_path.read_bytes()


def update_proposal_images() -> None:
    doc = Document(str(DOC_PATH))
    for rid, filename in UML_IMAGE_MAP.items():
        image_path = DIAGRAMS / filename
        if not image_path.exists():
            raise FileNotFoundError(image_path)
        replace_image_by_rid(doc, rid, image_path)
    doc.save(str(DOC_PATH))


def main() -> None:
    render_diagrams()
    for name in UML_IMAGE_MAP.values():
        print(f"Rendered: {DIAGRAMS / name}")
    update_proposal_images()
    print(f"Updated images in: {DOC_PATH}")


if __name__ == "__main__":
    main()
