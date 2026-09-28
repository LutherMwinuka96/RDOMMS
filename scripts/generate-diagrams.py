"""Generate RDOMMS system diagrams as PNG files."""
from __future__ import annotations

from pathlib import Path

import matplotlib.pyplot as plt
from matplotlib.patches import Circle, Ellipse, FancyArrowPatch, FancyBboxPatch, Polygon, Rectangle

OUTPUT_DIR = Path(__file__).resolve().parents[1] / "docs" / "diagrams"
TITLE_COLOR = "#1a365d"
BOX_FACE = "#eef4ff"
BOX_EDGE = "#2b6cb0"
ACCENT = "#2c5282"
NOTE_FACE = "#fffaf0"
NOTE_EDGE = "#c05621"


def save(fig, name: str) -> None:
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    path = OUTPUT_DIR / name
    fig.savefig(path, dpi=180, bbox_inches="tight", facecolor="white")
    plt.close(fig)
    print(f"Created: {path}")


def add_title(ax, title: str, subtitle: str = "RDO|MMS - Rural Development Organization Monitoring and Management System") -> None:
    ax.text(0.5, 0.97, title, ha="center", va="top", fontsize=16, fontweight="bold", color=TITLE_COLOR)
    ax.text(0.5, 0.935, subtitle, ha="center", va="top", fontsize=9, color="#4a5568")


def draw_box(ax, x, y, w, h, text, face=BOX_FACE, edge=BOX_EDGE, fontsize=8, bold=False):
    patch = FancyBboxPatch(
        (x, y),
        w,
        h,
        boxstyle="round,pad=0.02,rounding_size=0.015",
        linewidth=1.2,
        edgecolor=edge,
        facecolor=face,
    )
    ax.add_patch(patch)
    weight = "bold" if bold else "normal"
    ax.text(x + w / 2, y + h / 2, text, ha="center", va="center", fontsize=fontsize, weight=weight, wrap=True)


def draw_package(ax, x, y, w, h, title):
    rect = Rectangle((x, y), w, h, linewidth=1.5, edgecolor=ACCENT, facecolor="none", linestyle="--")
    ax.add_patch(rect)
    ax.text(x + 0.01, y + h - 0.015, title, fontsize=9, fontweight="bold", color=ACCENT, va="top")


def draw_use_case(ax, x, y, text):
    e = Ellipse((x, y), 0.16, 0.055, linewidth=1.2, edgecolor=BOX_EDGE, facecolor="#f7fafc")
    ax.add_patch(e)
    ax.text(x, y, text, ha="center", va="center", fontsize=7)


def draw_actor(ax, x, y, label):
    ax.add_patch(Circle((x, y + 0.05), 0.018, fill=False, linewidth=1.2, edgecolor="#2d3748"))
    ax.plot([x, x], [y + 0.032, y - 0.02], color="#2d3748", linewidth=1.2)
    ax.plot([x - 0.025, x + 0.025], [y + 0.01, y + 0.01], color="#2d3748", linewidth=1.2)
    ax.plot([x, x - 0.02], [y - 0.02, y - 0.06], color="#2d3748", linewidth=1.2)
    ax.plot([x, x + 0.02], [y - 0.02, y - 0.06], color="#2d3748", linewidth=1.2)
    ax.text(x, y - 0.085, label, ha="center", va="top", fontsize=8, fontweight="bold")


def connect(ax, x1, y1, x2, y2, style="-", text=None):
    arrow = FancyArrowPatch((x1, y1), (x2, y2), arrowstyle="-", mutation_scale=8, linewidth=1, color="#4a5568", linestyle=style)
    ax.add_patch(arrow)
    if text:
        ax.text((x1 + x2) / 2, (y1 + y2) / 2 + 0.015, text, ha="center", fontsize=7, color="#4a5568")


def connect_arrow(ax, x1, y1, x2, y2, text=None):
    arrow = FancyArrowPatch((x1, y1), (x2, y2), arrowstyle="->", mutation_scale=10, linewidth=1.1, color="#2d3748")
    ax.add_patch(arrow)
    if text:
        ax.text((x1 + x2) / 2, (y1 + y2) / 2 + 0.012, text, ha="center", fontsize=7, color="#4a5568", bbox=dict(boxstyle="round,pad=0.15", facecolor="white", edgecolor="none", alpha=0.85))


def generate_use_case_diagram() -> None:
    fig, ax = plt.subplots(figsize=(16, 10))
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis("off")
    add_title(ax, "Use Case Diagram")

    draw_actor(ax, 0.06, 0.72, "Guest")
    draw_actor(ax, 0.06, 0.48, "Staff / Field\nOfficer")
    draw_actor(ax, 0.06, 0.22, "Accountant")

    draw_actor(ax, 0.94, 0.72, "Department\nManager")
    draw_actor(ax, 0.94, 0.48, "Executive\nDirector")
    draw_actor(ax, 0.94, 0.22, "System\nAdministrator")

    packages = [
        (0.18, 0.78, 0.64, 0.16, "Authentication", ["Register", "Login", "Reset Password", "Update Profile"]),
        (0.18, 0.58, 0.64, 0.16, "Operations", ["Manage Beneficiaries", "Track Services", "Manage Projects", "Upload Documents"]),
        (0.18, 0.38, 0.64, 0.16, "Planning & Finance", ["Create Plans", "Monitor Progress", "Allocate Budget", "Record Expenses"]),
        (0.18, 0.18, 0.64, 0.16, "Administration & Reporting", ["Approve Requests", "Generate Reports", "Manage Users/Roles", "Configure System"]),
    ]

    positions = {}
    for px, py, pw, ph, title, cases in packages:
        draw_package(ax, px, py, pw, ph, title)
        cols = 2
        for i, case in enumerate(cases):
            cx = px + 0.12 + (i % cols) * 0.28
            cy = py + ph - 0.055 - (i // cols) * 0.055
            draw_use_case(ax, cx, cy, case)
            positions[case] = (cx, cy)

    links = {
        "Guest": ["Register", "Login", "Reset Password"],
        "Staff / Field\nOfficer": ["Manage Beneficiaries", "Track Services", "Manage Projects", "Upload Documents", "Create Plans", "Monitor Progress"],
        "Accountant": ["Allocate Budget", "Record Expenses", "Generate Reports"],
        "Department\nManager": ["Create Plans", "Monitor Progress", "Approve Requests", "Generate Reports"],
        "Executive\nDirector": ["Approve Requests", "Generate Reports", "Manage Users/Roles"],
        "System\nAdministrator": ["Manage Users/Roles", "Configure System", "Generate Reports"],
    }
    actor_coords = {
        "Guest": (0.08, 0.72),
        "Staff / Field\nOfficer": (0.08, 0.48),
        "Accountant": (0.08, 0.22),
        "Department\nManager": (0.92, 0.72),
        "Executive\nDirector": (0.92, 0.48),
        "System\nAdministrator": (0.92, 0.22),
    }
    for actor, cases in links.items():
        ax_pos = actor_coords[actor]
        for case in cases:
            if case in positions:
                cx, cy = positions[case]
                side_x = 0.11 if ax_pos[0] < 0.5 else 0.89
                connect(ax, side_x, ax_pos[1], cx - 0.08 if ax_pos[0] < 0.5 else cx + 0.08, cy, style=":")

    save(fig, "use-case-diagram.png")


def generate_class_diagram() -> None:
    fig, ax = plt.subplots(figsize=(16, 11))
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis("off")
    add_title(ax, "Class Diagram")

    classes = [
        (0.03, 0.72, 0.18, 0.18, "User\n─────────\n+ id: int\n+ fullname: string\n+ email: string\n+ role: string\n+ status: enum\n─────────\n+ login()\n+ updateProfile()"),
        (0.24, 0.72, 0.18, 0.18, "Role\n─────────\n+ role_id: string\n+ name: string\n+ permissions: JSON\n─────────\n+ hasPermission()"),
        (0.45, 0.72, 0.18, 0.18, "Session\n─────────\n+ session_token: string\n+ user_id: int\n+ expires_at: datetime\n─────────\n+ verify()"),
        (0.66, 0.72, 0.16, 0.18, "Organization\n─────────\n+ name: string\n+ email: string\n+ mission: text"),
        (0.84, 0.72, 0.13, 0.18, "Department\n─────────\n+ name: string\n+ description: text"),
        (0.03, 0.46, 0.18, 0.18, "Beneficiary\n─────────\n+ beneficiary_id: string\n+ fullname: string\n+ category: string\n+ district: string\n─────────\n+ register()\n+ search()"),
        (0.24, 0.46, 0.18, 0.18, "ServiceTracking\n─────────\n+ service_type: string\n+ service_date: date\n+ quantity: int\n─────────\n+ recordService()"),
        (0.45, 0.46, 0.18, 0.18, "Project\n─────────\n+ project_id: string\n+ name: string\n+ budget: decimal\n+ progress: int\n+ status: enum\n─────────\n+ create()\n+ updateStatus()"),
        (0.66, 0.46, 0.16, 0.18, "ProjectDocument\n─────────\n+ title: string\n+ category: string\n+ files: JSON\n─────────\n+ upload()"),
        (0.84, 0.46, 0.13, 0.18, "ProgressUpdate\n─────────\n+ progress: int\n+ status: string\n─────────\n+ monitor()"),
        (0.03, 0.2, 0.18, 0.18, "Plan\n─────────\n+ plan_id: string\n+ objective: text\n+ activities: JSON\n+ approval_status: enum\n─────────\n+ submit()\n+ approve()"),
        (0.24, 0.2, 0.18, 0.18, "BudgetAllocation\n─────────\n+ amount: decimal\n+ quarter: enum\n+ status: enum\n─────────\n+ allocate()"),
        (0.45, 0.2, 0.18, 0.18, "Expense\n─────────\n+ amount: decimal\n+ category: string\n+ status: enum\n─────────\n+ record()"),
        (0.66, 0.2, 0.16, 0.18, "Report\n─────────\n+ report_type: string\n+ period: string\n─────────\n+ generate()\n+ export()"),
        (0.84, 0.2, 0.13, 0.18, "Notification\n─────────\n+ title: string\n+ severity: enum\n─────────\n+ notify()"),
        (0.34, 0.02, 0.32, 0.12, "Database (config/db.php)\n─────────\n+ getConnection(): PDO\n+ verifySession()\n+ logActivity()\n+ requireRole()"),
    ]

    centers = {}
    class_keys = [
        "User", "Role", "Session", "Organization", "Department",
        "Beneficiary", "ServiceTracking", "Project", "ProjectDocument", "ProgressUpdate",
        "Plan", "BudgetAllocation", "Expense", "Report", "Notification", "Database",
    ]
    for (x, y, w, h, text), key in zip(classes, class_keys):
        draw_box(ax, x, y, w, h, text, fontsize=7)
        centers[key] = (x + w / 2, y + h / 2)

    relations = [
        ("User", "Role", "1", "1"),
        ("User", "Session", "1", "0..*"),
        ("Organization", "Department", "1", "0..*"),
        ("Beneficiary", "ServiceTracking", "1", "0..*"),
        ("Project", "ProjectDocument", "1", "0..*"),
        ("Project", "ProgressUpdate", "1", "0..*"),
        ("User", "Beneficiary", "1", "0..*", 0.12, 0.55),
        ("User", "Project", "1", "0..*", 0.54, 0.55),
        ("User", "Plan", "1", "0..*", 0.12, 0.29),
        ("User", "BudgetAllocation", "1", "0..*", 0.33, 0.29),
        ("User", "Notification", "1", "0..*", 0.90, 0.29),
        ("Database", "User", "1", "0..*", 0.20, 0.14),
    ]

    for rel in relations:
        if len(rel) == 6:
            a, b, c, d, mx, my = rel
            connect_arrow(ax, centers[a][0], centers[a][1], mx, my, f"{c} to {d}")
            connect_arrow(ax, mx, my, centers[b][0], centers[b][1])
        else:
            a, b, c, d = rel
            connect_arrow(ax, centers[a][0], centers[a][1], centers[b][0], centers[b][1], f"{c} to {d}")

    save(fig, "class-diagram.png")


def generate_sequence_diagram() -> None:
    fig, ax = plt.subplots(figsize=(14, 9))
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis("off")
    add_title(ax, "Sequence Diagram - User Login and Dashboard Access")

    lifelines = [
        (0.12, "User"),
        (0.30, "Browser\n(Frontend)"),
        (0.50, "Auth API\n(login.php)"),
        (0.70, "Database\n(MySQL)"),
        (0.88, "Dashboard\n(RDOMMS.html)"),
    ]
    y_top = 0.86
    y_bottom = 0.08
    for x, label in lifelines:
        draw_box(ax, x - 0.07, y_top - 0.03, 0.14, 0.06, label, fontsize=7, bold=True)
        ax.plot([x, x], [y_top - 0.03, y_bottom], linestyle="--", color="#a0aec0", linewidth=1)

    steps = [
        (0.12, 0.50, 0.30, 0.50, 0.78, "1. Enter email & password"),
        (0.30, 0.50, 0.50, 0.50, 0.72, "2. POST /api/auth/login.php"),
        (0.50, 0.70, 0.70, 0.50, 0.66, "3. Validate credentials"),
        (0.70, 0.50, 0.50, 0.70, 0.60, "4. Return user record"),
        (0.50, 0.50, 0.70, 0.50, 0.54, "5. Create session token"),
        (0.70, 0.50, 0.50, 0.70, 0.48, "6. Store in user_sessions"),
        (0.50, 0.30, 0.30, 0.50, 0.42, "7. JSON success + session"),
        (0.30, 0.50, 0.12, 0.50, 0.36, "8. Store session locally"),
        (0.12, 0.88, 0.30, 0.88, 0.30, "9. Open dashboard"),
        (0.30, 0.50, 0.88, 0.50, 0.24, "10. Load dashboard page"),
        (0.88, 0.70, 0.70, 0.50, 0.18, "11. Fetch stats/charts API"),
        (0.70, 0.50, 0.88, 0.70, 0.12, "12. Return dashboard data"),
    ]

    for x1, x2, x3, x4, y, label in steps:
        connect_arrow(ax, x1, y, x2 if x1 != x3 else x3, y)
        ax.text(0.02, y, label, fontsize=7, va="center", color="#2d3748")

    save(fig, "sequence-diagram.png")


def generate_activity_diagram() -> None:
    fig, ax = plt.subplots(figsize=(12, 12))
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis("off")
    add_title(ax, "Activity Diagram - Beneficiary Registration and Service Tracking")

    def node(x, y, text, kind="action"):
        if kind == "start":
            ax.add_patch(Circle((x, y), 0.018, facecolor="#2d3748", edgecolor="#2d3748"))
        elif kind == "end":
            ax.add_patch(Circle((x, y), 0.018, facecolor="white", edgecolor="#2d3748", linewidth=2))
            ax.add_patch(Circle((x, y), 0.01, facecolor="#2d3748", edgecolor="#2d3748"))
        elif kind == "decision":
            d = 0.055
            pts = [(x, y + d), (x + d, y), (x, y - d), (x - d, y)]
            ax.add_patch(Polygon(pts, closed=True, facecolor=NOTE_FACE, edgecolor=NOTE_EDGE, linewidth=1.2))
            ax.text(x, y, text, ha="center", va="center", fontsize=7)
        else:
            draw_box(ax, x - 0.11, y - 0.025, 0.22, 0.05, text, fontsize=7)

    y = 0.9
    node(0.5, y, "", "start")
    connect_arrow(ax, 0.5, y - 0.02, 0.5, y - 0.06)
    y -= 0.08
    node(0.5, y, "Staff logs into RDOMMS")
    connect_arrow(ax, 0.5, y - 0.03, 0.5, y - 0.07)
    y -= 0.09
    node(0.5, y, "Open Register Beneficiary form")
    connect_arrow(ax, 0.5, y - 0.03, 0.5, y - 0.07)
    y -= 0.09
    node(0.5, y, "Enter beneficiary details")
    connect_arrow(ax, 0.5, y - 0.03, 0.5, y - 0.07)
    y -= 0.09
    node(0.5, y, "Valid data?", "decision")
    connect_arrow(ax, 0.5, y - 0.06, 0.5, y - 0.10)
    ax.text(0.56, y - 0.03, "No", fontsize=7)
    connect(ax, 0.555, y, 0.78, y, style=":")
    connect(ax, 0.78, y, 0.78, y + 0.16, style=":")
    connect(ax, 0.78, y + 0.16, 0.61, y + 0.16, style=":")
    ax.text(0.62, y + 0.17, "Show validation errors", fontsize=7, color="#c05621")
    y -= 0.12
    node(0.5, y, "POST /api/beneficiaries.php")
    connect_arrow(ax, 0.5, y - 0.03, 0.5, y - 0.07)
    y -= 0.09
    node(0.5, y, "Save record in MySQL")
    connect_arrow(ax, 0.5, y - 0.03, 0.5, y - 0.07)
    y -= 0.09
    node(0.5, y, "Log activity & notify user")
    connect_arrow(ax, 0.5, y - 0.03, 0.5, y - 0.07)
    y -= 0.09
    node(0.5, y, "Record service delivery?", "decision")
    ax.text(0.56, y - 0.03, "Yes", fontsize=7)
    connect_arrow(ax, 0.5, y - 0.06, 0.5, y - 0.10)
    y -= 0.12
    node(0.5, y, "Create service tracking entry")
    connect_arrow(ax, 0.5, y - 0.03, 0.5, y - 0.07)
    y -= 0.09
    node(0.5, y, "Update beneficiary status")
    connect_arrow(ax, 0.5, y - 0.03, 0.5, y - 0.07)
    y -= 0.09
    node(0.5, y, "Display success message")
    connect_arrow(ax, 0.5, y - 0.03, 0.5, y - 0.07)
    y -= 0.08
    node(0.5, y, "", "end")

    save(fig, "activity-diagram.png")


def generate_deployment_diagram() -> None:
    fig, ax = plt.subplots(figsize=(14, 8))
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis("off")
    add_title(ax, "Deployment Diagram")

    draw_package(ax, 0.05, 0.58, 0.28, 0.28, "Client Tier")
    draw_box(ax, 0.08, 0.72, 0.10, 0.08, "Desktop\nBrowser", fontsize=8)
    draw_box(ax, 0.20, 0.72, 0.10, 0.08, "Mobile\nBrowser", fontsize=8)
    draw_box(ax, 0.08, 0.60, 0.22, 0.08, "HTML / CSS / JavaScript\n(RDOMMS Frontend)", fontsize=7)

    draw_package(ax, 0.38, 0.50, 0.28, 0.36, "Application Server (WAMP)")
    draw_box(ax, 0.41, 0.72, 0.22, 0.08, "Apache HTTP Server", fontsize=8, bold=True)
    draw_box(ax, 0.41, 0.60, 0.22, 0.08, "PHP 8 Runtime", fontsize=8)
    draw_box(ax, 0.41, 0.52, 0.22, 0.06, "REST API Endpoints\n(/api/*.php)", fontsize=7)

    draw_package(ax, 0.70, 0.50, 0.25, 0.36, "Data Tier")
    draw_box(ax, 0.73, 0.72, 0.19, 0.08, "MySQL Server\n(rdomms_db)", fontsize=8, bold=True)
    draw_box(ax, 0.73, 0.60, 0.19, 0.08, "File Storage\n/uploads\n/storage", fontsize=7)
    draw_box(ax, 0.73, 0.52, 0.19, 0.06, "SMTP / Mail Outbox\n(config/mailer.php)", fontsize=7)

    connect_arrow(ax, 0.33, 0.64, 0.41, 0.64, "HTTP/HTTPS")
    connect_arrow(ax, 0.63, 0.64, 0.73, 0.64, "PDO / SQL")
    connect_arrow(ax, 0.52, 0.52, 0.73, 0.56, "Read/Write Files")
    connect_arrow(ax, 0.52, 0.72, 0.73, 0.52, "Send Email")

    ax.text(0.5, 0.18, "Deployment Environment: Local/Institutional WAMP stack or LAMP hosting\nProtocol: HTTP requests from browser to PHP APIs, persisted in MySQL and file system", ha="center", fontsize=8, color="#4a5568")

    save(fig, "deployment-diagram.png")


def generate_erd_diagram() -> None:
    fig, ax = plt.subplots(figsize=(18, 12))
    ax.set_xlim(0, 1)
    ax.set_ylim(0, 1)
    ax.axis("off")
    add_title(ax, "Entity Relationship Diagram (ERD)")

    entities = [
        (0.02, 0.78, "users", ["PK id", "user_id", "fullname", "email", "role", "status"]),
        (0.18, 0.78, "organizations", ["PK id", "org_id", "name", "email", "mission"]),
        (0.34, 0.78, "departments", ["PK id", "name", "FK organization_id"]),
        (0.50, 0.78, "roles", ["PK id", "role_id", "name", "permissions"]),
        (0.66, 0.78, "user_sessions", ["PK id", "FK user_id", "session_token", "expires_at"]),
        (0.82, 0.78, "activity_logs", ["PK id", "FK user_id", "action", "module"]),
        (0.02, 0.56, "projects", ["PK id", "project_id", "name", "budget", "status", "FK created_by"]),
        (0.18, 0.56, "beneficiaries", ["PK id", "beneficiary_id", "fullname", "category", "FK registered_by"]),
        (0.34, 0.56, "service_tracking", ["PK id", "service_id", "FK beneficiary_id", "service_type"]),
        (0.50, 0.56, "project_documents", ["PK id", "document_id", "title", "FK created_by"]),
        (0.66, 0.56, "progress_updates", ["PK id", "update_id", "project_name", "progress", "FK created_by"]),
        (0.82, 0.56, "planning_evidence", ["PK id", "evidence_id", "title", "FK created_by"]),
        (0.02, 0.34, "monthly_plans", ["PK id", "plan_id", "month", "year", "FK created_by"]),
        (0.18, 0.34, "quarterly_plans", ["PK id", "plan_id", "quarter", "year", "FK created_by"]),
        (0.34, 0.34, "annual_plans", ["PK id", "plan_id", "year", "FK created_by"]),
        (0.50, 0.34, "targets", ["PK id", "target_id", "name", "target_value", "FK created_by"]),
        (0.66, 0.34, "budget_allocations", ["PK id", "allocation_id", "amount", "FK created_by"]),
        (0.82, 0.34, "expenses", ["PK id", "expense_id", "amount", "FK created_by"]),
        (0.02, 0.12, "notifications", ["PK id", "FK user_id", "title", "severity"]),
        (0.18, 0.12, "approval_requests", ["PK id", "request_id", "status", "FK created_by"]),
        (0.34, 0.12, "notification_threads", ["PK id", "thread_id", "FK owner_user_id"]),
        (0.50, 0.12, "notification_messages", ["PK id", "message_id", "FK thread_id", "body"]),
        (0.66, 0.12, "scheduled_reports", ["PK id", "schedule_id", "report_type", "frequency"]),
        (0.82, 0.12, "report_history", ["PK id", "report_id", "report_type", "generated_at"]),
    ]

    centers = {}
    for x, y, name, fields in entities:
        h = 0.055 + len(fields) * 0.018
        draw_box(ax, x, y, 0.14, h, name + "\n" + "\n".join(fields), fontsize=6.5, face="#f0fff4", edge="#276749")
        centers[name] = (x + 0.07, y + h / 2)

    rels = [
        ("users", "user_sessions"),
        ("users", "activity_logs"),
        ("users", "projects"),
        ("users", "beneficiaries"),
        ("organizations", "departments"),
        ("beneficiaries", "service_tracking"),
        ("users", "monthly_plans"),
        ("users", "budget_allocations"),
        ("users", "expenses"),
        ("users", "notifications"),
        ("users", "approval_requests"),
        ("users", "notification_threads"),
        ("notification_threads", "notification_messages"),
    ]

    for a, b in rels:
        connect(ax, centers[a][0], centers[a][1], centers[b][0], centers[b][1], style="-")

    ax.text(0.5, 0.03, "Core relationships shown. Additional tables: password_reset_tokens, system_settings, app_storage", ha="center", fontsize=8, color="#4a5568")
    save(fig, "erd-diagram.png")


def main() -> None:
    generate_use_case_diagram()
    generate_class_diagram()
    generate_sequence_diagram()
    generate_activity_diagram()
    generate_deployment_diagram()
    generate_erd_diagram()
    print(f"All diagrams saved to: {OUTPUT_DIR}")


if __name__ == "__main__":
    main()
