"""Generate RDO|MMS User Manual PDF."""
from __future__ import annotations

from pathlib import Path

from fpdf import FPDF

OUTPUT = Path(__file__).resolve().parents[1] / "help" / "RDOMMS-User-Manual.pdf"


class ManualPDF(FPDF):
    def __init__(self) -> None:
        super().__init__()
        self.set_margins(18, 18, 18)
        self.set_auto_page_break(auto=True, margin=18)

    def usable_width(self) -> float:
        return self.w - self.l_margin - self.r_margin

    def header(self) -> None:
        self.set_font("Helvetica", "B", 10)
        self.set_text_color(80, 80, 80)
        self.cell(0, 8, "RDO|MMS User Manual", align="L")
        self.ln(6)

    def footer(self) -> None:
        self.set_y(-15)
        self.set_font("Helvetica", "I", 9)
        self.set_text_color(120, 120, 120)
        self.cell(0, 10, f"Page {self.page_no()}", align="C")


def section_title(pdf: ManualPDF, title: str) -> None:
    pdf.ln(4)
    pdf.set_x(pdf.l_margin)
    pdf.set_font("Helvetica", "B", 14)
    pdf.set_text_color(15, 33, 57)
    pdf.multi_cell(pdf.usable_width(), 8, title)
    pdf.ln(2)


def sub_title(pdf: ManualPDF, title: str) -> None:
    pdf.set_x(pdf.l_margin)
    pdf.set_font("Helvetica", "B", 11)
    pdf.set_text_color(37, 99, 235)
    pdf.multi_cell(pdf.usable_width(), 7, title)
    pdf.ln(1)


def body_text(pdf: ManualPDF, text: str) -> None:
    pdf.set_x(pdf.l_margin)
    pdf.set_font("Helvetica", "", 10)
    pdf.set_text_color(40, 40, 40)
    pdf.multi_cell(pdf.usable_width(), 6, text)
    pdf.ln(2)


def bullet_list(pdf: ManualPDF, items: list[str]) -> None:
    pdf.set_font("Helvetica", "", 10)
    pdf.set_text_color(40, 40, 40)
    for item in items:
        pdf.set_x(pdf.l_margin)
        pdf.multi_cell(pdf.usable_width(), 6, f"- {item}")
    pdf.ln(2)


def build_manual() -> ManualPDF:
    pdf = ManualPDF()
    pdf.add_page()

    pdf.set_x(pdf.l_margin)
    pdf.set_font("Helvetica", "B", 22)
    pdf.set_text_color(15, 33, 57)
    pdf.multi_cell(pdf.usable_width(), 10, "RDO|MMS User Manual")
    pdf.ln(2)
    pdf.set_x(pdf.l_margin)
    pdf.set_font("Helvetica", "", 12)
    pdf.set_text_color(80, 80, 80)
    pdf.multi_cell(
        pdf.usable_width(),
        7,
        "Rural Development Organization Monitoring and Management System\n"
        "Version 2.0 | Iringa Region, Tanzania",
    )
    pdf.ln(4)
    body_text(
        pdf,
        "This manual explains how to use RDO|MMS for daily monitoring, management, reporting, "
        "and administration tasks. It is intended for staff, managers, finance officers, and "
        "system administrators.",
    )

    section_title(pdf, "1. Introduction")
    body_text(
        pdf,
        "RDO|MMS is a web-based system for rural development organizations. It helps users "
        "register beneficiaries, manage projects, create plans, track budgets and expenses, "
        "generate reports, and collaborate through notifications and approval requests.",
    )
    bullet_list(
        pdf,
        [
            "Access the system using a modern browser such as Chrome, Firefox, Edge, or Safari.",
            "Use your registered email and password to sign in.",
            "Your role determines which modules and actions you can access.",
        ],
    )

    section_title(pdf, "2. Getting Started")
    sub_title(pdf, "2.1 Sign In")
    bullet_list(
        pdf,
        [
            "Open the system landing page and click Sign In.",
            "Enter your email address and password.",
            "After successful login, the dashboard opens automatically.",
        ],
    )
    sub_title(pdf, "2.2 Registration")
    bullet_list(
        pdf,
        [
            "New users can register from the Sign Up page.",
            "Complete all required fields including name, email, role, and department.",
            "New accounts may remain pending until approved by an administrator.",
        ],
    )
    sub_title(pdf, "2.3 Password Reset")
    bullet_list(
        pdf,
        [
            "Click Forgot Password on the sign-in page.",
            "Enter your registered email address.",
            "Open the reset link sent to your email and set a new password.",
        ],
    )
    sub_title(pdf, "2.4 Dashboard Overview")
    bullet_list(
        pdf,
        [
            "The dashboard shows summary statistics, charts, and recent activity.",
            "Use the sidebar to open modules such as Projects, Beneficiaries, Finance, and Reports.",
            "Use the profile menu at the top-right for account settings, help, and logout.",
        ],
    )

    section_title(pdf, "3. User Account and Profile")
    sub_title(pdf, "3.1 My Profile")
    bullet_list(
        pdf,
        [
            "Open My Profile from the user dropdown menu.",
            "Review your name, email, role, department, and organization details.",
            "Click Edit, update your information, then click Save Changes.",
        ],
    )
    sub_title(pdf, "3.2 Profile Photo")
    bullet_list(
        pdf,
        [
            "Open My Profile and choose Change Photo.",
            "Select a JPG, PNG, or WEBP image.",
            "Save the profile to apply the new photo.",
        ],
    )
    sub_title(pdf, "3.3 Change Password")
    bullet_list(
        pdf,
        [
            "Open Change Password from the user dropdown.",
            "Enter your current password and new password.",
            "Click Update Password to save the change.",
        ],
    )

    section_title(pdf, "4. Beneficiary Management")
    sub_title(pdf, "4.1 Register Beneficiary")
    bullet_list(
        pdf,
        [
            "Go to Beneficiaries > Register Beneficiary.",
            "Enter beneficiary name, gender, category, location, and contact details.",
            "Submit the form to save the record.",
        ],
    )
    sub_title(pdf, "4.2 Beneficiary List and Search")
    bullet_list(
        pdf,
        [
            "Open Beneficiary List to view all registered beneficiaries.",
            "Use Search and Filter to find records by name, category, district, or status.",
        ],
    )
    sub_title(pdf, "4.3 Service Tracking")
    bullet_list(
        pdf,
        [
            "Go to Beneficiaries > Service Tracking.",
            "Record services delivered to a beneficiary such as training, inputs, or support visits.",
            "Each service entry includes date, type, provider, and description.",
        ],
    )

    section_title(pdf, "5. Project Management")
    sub_title(pdf, "5.1 Create Project")
    bullet_list(
        pdf,
        [
            "Go to Projects > Create Project.",
            "Enter project name, description, location, budget, dates, and team members.",
            "Submit the project to save it in the system.",
        ],
    )
    sub_title(pdf, "5.2 Track Project Status")
    bullet_list(
        pdf,
        [
            "Open Projects > Status Tracking to monitor progress.",
            "Update project status such as not-started, ongoing, delayed, or completed.",
            "Review all projects from Projects > All Projects.",
        ],
    )
    sub_title(pdf, "5.3 Project Documents")
    bullet_list(
        pdf,
        [
            "Open Documents > Project Documents.",
            "Upload proposals, contracts, reports, and supporting files.",
            "Organize documents by project, category, and date.",
        ],
    )

    section_title(pdf, "6. Planning and Monitoring")
    bullet_list(
        pdf,
        [
            "Create monthly, quarterly, and annual plans from the Planning module.",
            "Add objectives, activities, budgets, and responsible staff.",
            "Submit plans for approval when required.",
            "Use Progress Monitoring to update activity progress and challenges.",
            "Use Targets and Indicators to track measurable outcomes.",
            "Upload evidence files from Upload Evidence where available.",
        ],
    )

    section_title(pdf, "7. Finance Module")
    sub_title(pdf, "7.1 Budget Allocation")
    bullet_list(
        pdf,
        [
            "Go to Finance > Budget Allocation.",
            "Create allocations by department, quarter, and fiscal year.",
            "Submit allocations for approval when required.",
        ],
    )
    sub_title(pdf, "7.2 Expense Tracking")
    bullet_list(
        pdf,
        [
            "Go to Finance > Expense Tracking.",
            "Record expenses with date, category, department, amount, and reference.",
            "Approved and pending expenses can be reviewed for reporting.",
        ],
    )
    sub_title(pdf, "7.3 Financial Reports and Variance Analysis")
    bullet_list(
        pdf,
        [
            "Open Financial Reports to review spending summaries.",
            "Use Variance Analysis to compare planned budgets against actual expenses.",
        ],
    )

    section_title(pdf, "8. Reports")
    bullet_list(
        pdf,
        [
            "Open Reports > Generate Reports.",
            "Select the report type and date range.",
            "Preview the report and export to PDF or Excel when needed.",
            "Review report history for previously generated reports.",
        ],
    )

    section_title(pdf, "9. Notifications and Collaboration")
    bullet_list(
        pdf,
        [
            "Open Notifications to view system alerts, messages, and approval requests.",
            "Use Messages to communicate with other users inside the system.",
            "Review Approval Requests and approve or reject submitted items.",
            "Check unread notifications from the notification icon on the dashboard.",
        ],
    )

    section_title(pdf, "10. User and Role Management")
    bullet_list(
        pdf,
        [
            "Administrators can open Users > All Users to manage accounts.",
            "Use Add User to create new staff accounts.",
            "Use Role Management to review permissions by role.",
            "Review Activity Log to monitor important user actions.",
        ],
    )

    section_title(pdf, "11. System Settings")
    bullet_list(
        pdf,
        [
            "General Settings: configure application preferences.",
            "Organization Profile: update organization name, logo, and contact details.",
            "Notification Settings: manage alert preferences.",
            "Email SMTP: configure outgoing email for password reset and notifications.",
            "Data Backup: export and restore database backups.",
            "System Logs: review system events and activity records.",
        ],
    )

    section_title(pdf, "12. Roles and Access Control")
    body_text(
        pdf,
        "RDO|MMS uses Role-Based Access Control (RBAC). Each user is assigned a role that "
        "determines which modules can be viewed, created, edited, or approved.",
    )
    bullet_list(
        pdf,
        [
            "Executive Director / Admin: full system access.",
            "Department Manager: department oversight, planning, approvals, and reports.",
            "Staff / Field Officer: operational data entry for beneficiaries and projects.",
            "Accountant: finance modules and financial reports.",
        ],
    )

    section_title(pdf, "13. Troubleshooting")
    bullet_list(
        pdf,
        [
            "If login fails, confirm your email, password, and account approval status.",
            "If a page does not load, refresh the browser and confirm WAMP/server is running.",
            "If password reset email does not arrive, check SMTP settings or mail outbox.",
            "If a module is missing, your role may not have permission to access it.",
            "For additional help, open Help & Support from the user menu.",
        ],
    )

    section_title(pdf, "14. Support Contacts")
    bullet_list(
        pdf,
        [
            "Email: support@rdomms.org",
            "Phone: +255 123 456 789",
            "WhatsApp: +255 987 654 321",
            "Support Hours: Monday - Friday, 8:00 AM - 6:00 PM EAT",
        ],
    )

    pdf.ln(6)
    pdf.set_x(pdf.l_margin)
    pdf.set_font("Helvetica", "I", 9)
    pdf.set_text_color(100, 100, 100)
    pdf.multi_cell(
        pdf.usable_width(),
        5,
        "Document generated for RDO|MMS. For the latest online version, open help/user-manual.html.",
    )
    return pdf


def main() -> None:
    OUTPUT.parent.mkdir(parents=True, exist_ok=True)
    pdf = build_manual()
    pdf.output(str(OUTPUT))
    print(f"Created: {OUTPUT}")


if __name__ == "__main__":
    main()
