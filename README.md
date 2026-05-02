# 🤝 Donation Management System — Al-Kalim Al-Tayyib Charity Organisation 🇸🇩

<p align="center">
  <img src="https://img.shields.io/badge/Laravel-10+-FF2D20?style=for-the-badge&logo=laravel&logoColor=white" alt="Laravel">
  <img src="https://img.shields.io/badge/PHP-8.1+-777BB4?style=for-the-badge&logo=php&logoColor=white" alt="PHP">
  <img src="https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white" alt="MySQL">
  <img src="https://img.shields.io/badge/Bootstrap-5-7952B3?style=for-the-badge&logo=bootstrap&logoColor=white" alt="Bootstrap">
  <img src="https://img.shields.io/badge/Status-Active%20Development-brightgreen?style=for-the-badge" alt="Status">
</p>

---

## About the Project

**Al-Kalim Al-Tayyib** is a Sudanese charity organisation that connects generous donors with families and individuals in need. This web-based Donation Management System was built to **automate and professionalise** the organisation's aid distribution process — transitioning away from informal, manual coordination over social media toward a structured, transparent, and accountable digital platform.

The system provides dedicated portals for donors, beneficiaries, and administrators, enabling end-to-end management of donation campaigns, aid applications, and document verification under one unified platform.

---

## Key Modules

### 👤 Donor Portal
- Browse and contribute to active donation campaigns
- Personal donation history with downloadable records
- Real-time campaign progress bars showing funding status
- Account dashboard with contribution summaries

### 📄 Beneficiary Portal
- Submit aid applications through a guided, structured form
- Secure document upload for identity and need verification
- Track application status from submission through approval
- Receive transparent feedback on aid decisions

### 🛠️ Admin Dashboard
- Create, manage, and monitor donation campaigns end-to-end
- Review and verify beneficiary documents and eligibility
- Approve or decline aid applications with recorded justifications
- View system-wide analytics: total funds raised, disbursements, and active campaigns

---

## Technical Stack

| Layer | Technology |
|---|---|
| Backend Framework | Laravel 10+ |
| Language | PHP 8.1+ |
| Database | MySQL 8.0 |
| Frontend | Bootstrap 5, JavaScript (Vanilla) |
| Template Engine | Blade (Laravel) |
| Version Control | Git / GitHub |
| Development IDE | Visual Studio Code |
| Local Server | XAMPP (Apache + MySQL) |

---

## Academic Context

This project is submitted as a **Final Year Project (Semester 5)** for the degree:

> **Bachelor of Information Technology (BIT)**
> Universiti Tun Hussein Onn Malaysia (UTHM)

**Developer:** Elsamani Ahmed
**Supervisor:** *(Prof.Nazri Bin Mohd Nawi)*
**Organisation Partner:** Al-Kalim Al-Tayyib Charity Organisation

The project applies software engineering principles including MVC architecture, role-based access control (RBAC), and relational database design to solve a real-world problem for a functioning charitable institution.

---

## Getting Started (Local Setup)

```bash
# 1. Clone the repository
git clone https://github.com/your-username/al-kalimah-v4.git
cd al-kalimah-v4

# 2. Install PHP dependencies
composer install

# 3. Install JavaScript dependencies
npm install && npm run build

# 4. Configure environment
cp .env.example .env
php artisan key:generate

# 5. Set up the database
# Create a MySQL database, update .env with credentials, then run:
php artisan migrate --seed

# 6. Start the development server
php artisan serve
```

---

## License

This project is developed for academic and non-profit purposes.
The source code is open for review and educational use.

---

<p align="center">Built with purpose for the people of Sudan 🇸🇩 — Elsamani Ahmed, 2025</p>
