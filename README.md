# 🥐 Pasticcere Pro — Bakery Management System

<div align="center">

![Pasticcere Pro](https://img.shields.io/badge/Pasticcere%20Pro-Bakery%20Management-D4A017?style=for-the-badge&logoColor=white)

[![Laravel](https://img.shields.io/badge/Laravel-FF2D20?style=flat-square&logo=laravel&logoColor=white)](https://laravel.com)
[![PHP](https://img.shields.io/badge/PHP-8.2+-777BB4?style=flat-square&logo=php&logoColor=white)](https://php.net)
[![MySQL](https://img.shields.io/badge/MySQL-4479A1?style=flat-square&logo=mysql&logoColor=white)](https://mysql.com)
[![Bootstrap](https://img.shields.io/badge/Bootstrap-5-7952B3?style=flat-square&logo=bootstrap&logoColor=white)](https://getbootstrap.com)
[![jQuery](https://img.shields.io/badge/jQuery-0769AD?style=flat-square&logo=jquery&logoColor=white)](https://jquery.com)

**An enterprise-grade bakery management platform built for a professional bakery chain in Italy.**

</div>

---

## 📋 Table of Contents

- [Overview](#-overview)
- [Core Modules](#-core-modules)
- [Key Features](#-key-features)
- [Tech Stack](#-tech-stack)
- [Installation](#-installation)
- [System Workflow](#-system-workflow)
- [Screenshots](#-screenshots)
- [License](#-license)

---

## 🔍 Overview

**Pasticcere Pro** is a full-stack, enterprise-level bakery management system developed for a professional bakery chain based in Italy. Built on Laravel, the platform digitizes and automates the complete operational workflow of a bakery business — from ingredient tracking and recipe costing to daily showcase planning, financial analysis, and production management.

The system was designed around real-world bakery operations, with a strong focus on mathematical precision in cost calculation, margin analysis, and break-even modeling.

---

## 📦 Core Modules

### 🥣 Ingredient Management
- Add and manage the full ingredient catalog with price per kg
- Support for alternative name aliases for automatic invoice matching
- AI-powered invoice scanning using **Google Vision OCR + GPT-4o** — upload a supplier invoice and ingredients are extracted and matched automatically

### 🧁 Recipe Management
- Build recipes by selecting ingredients with quantity and cost inputs
- Apply loss and waste percentages to get accurate post-production weight
- Configure labor cost using internal (€/min) or external rates
- Set packaging costs and choose sales method — by piece or per kg
- Apply VAT rate and instantly calculate potential profit margin
- Color-coded margin indicators (green / red) across all recipes
- Average margin summary calculated automatically across the full catalog

### 📊 Break-Even & Labor Calculator
- Model complete monthly and daily cost structures
- Covers labor, electricity, packaging, leasing, and operational overhead
- Calculate accurate break-even points per product and per day

### 🪟 Daily Showcase Planner
- Select products for the day's display window
- Define selling prices and planned quantities
- Auto-calculate expected revenue, projected margins, and anticipated waste

### 📦 Inventory Management
- Track stock levels across ingredients and finished products
- Monitor consumption against production logs

### 💰 Financial Reporting
- Earnings and costs broken down by category
- Date-range filters for custom period analysis
- Top 5 best-selling products by quantity sold
- Global average margin tracking across all categories

### 🚚 Supplies & Returns
- Manage external supplier orders
- Handle returned goods and log adjustments

### 🏭 Production Logs
- Record daily production runs per recipe
- Track quantities produced against planned showcase targets

### 📈 Sales Comparison
- Compare sales performance across time periods
- Identify top-performing and underperforming products

### 👥 CRM & User Management
- Role-based access control for Admins, Staff, and Managers
- User creation, management, and permission assignment

### 📰 Blog & News
- Internal news and update section for staff communication

---

## ✨ Key Features

| Feature | Details |
|---|---|
| AI Invoice Scanner | Google Vision OCR + GPT-4o auto-extracts ingredients from supplier invoices |
| Dynamic Cost Engine | Real-time margin calculation factoring ingredients, labor, packaging, and VAT |
| Break-Even Modeling | Full monthly/daily cost structure with break-even point calculation |
| Role-Based Access | Multi-role system with permission-controlled module access |
| Showcase Planning | Daily display window planning with revenue and waste projection |
| Financial Dashboard | Category-level earnings, costs, and margin analytics with date filters |
| Production Tracking | Logs production runs and compares against planned quantities |

---

## 🛠 Tech Stack

| Layer | Technology |
|---|---|
| **Backend Framework** | Laravel |
| **Language** | PHP 8.2+ |
| **Database** | MySQL |
| **Frontend** | Bootstrap 5, HTML5, CSS3 |
| **Interactivity** | jQuery, AJAX |
| **Templating** | Blade (Laravel) |
| **AI Integration** | Google Vision OCR + GPT-4o |

---

## ⚙️ Installation

### Prerequisites
- PHP >= 8.2
- Composer
- MySQL 8.0+
- Google Vision API key (for AI invoice scanning)
- OpenAI API key (for GPT-4o extraction)

### Steps
```bash
# 1. Clone the repository
git clone https://github.com/your-username/pasticcere-pro.git
cd pasticcere-pro

# 2. Install PHP dependencies
composer install

# 3. Copy environment file
cp .env.example .env

# 4. Generate application key
php artisan key:generate

# 5. Configure your .env file
# Set DB_DATABASE, DB_USERNAME, DB_PASSWORD
# Set GOOGLE_VISION_API_KEY
# Set OPENAI_API_KEY

# 6. Run migrations and seeders
php artisan migrate --seed

# 7. Start the development server
php artisan serve
```

Visit `http://127.0.0.1:8000` to access the application.

---

## 🗄 Database Design

### Core Entities
```
users                → id, name, email, role, permissions
ingredients          → id, name, aliases, price_per_kg
recipes              → id, name, category, department, sales_method, price, vat_rate
recipe_ingredients   → id, recipe_id, ingredient_id, quantity_g, loss_percentage
labor_costs          → id, recipe_id, working_time, cost_per_minute, labor_cost
showcases            → id, date, recipe_id, quantity, selling_price, projected_margin
production_logs      → id, recipe_id, date, quantity_produced
sales                → id, recipe_id, date, quantity_sold, revenue
financial_reports    → id, category, period, earnings, costs, margin
inventory            → id, ingredient_id, quantity, last_updated
```

---

## 🔄 System Workflow
```
1. Admin configures ingredients with price per kg
         │
         ▼
2. Recipes are built with ingredients, labor, packaging, and VAT
         │
         ▼
3. System auto-calculates cost per unit and profit margin
         │
         ▼
4. Break-even calculator models daily and monthly cost structure
         │
         ▼
5. Daily showcase planner selects recipes and sets quantities/prices
         │
         ▼
6. System projects expected revenue, margins, and waste
         │
         ▼
7. Production team logs actual quantities produced
         │
         ▼
8. Sales are recorded and compared against projections
         │
         ▼
9. Financial dashboard reflects earnings, costs, and margins by category
         │
         ▼
10. Management reviews reports and adjusts pricing or production strategy
```

---

## 📸 Screenshots

### Login Page
![Login](screenshots/login.png)

### Recipe Builder
![Recipe Builder](screenshots/recipe-builder.png)

### AI Invoice Extraction
![AI Invoice](screenshots/ai-invoice.png)

### Recipe List with Margin Analysis
![Recipe List](screenshots/recipe-list.png)

### Business Overview Dashboard
![Dashboard](screenshots/dashboard.png)

---

## 🔐 Security

- Role-based middleware protecting all routes
- CSRF protection on all forms
- Permission-controlled module access per user role
- Secure API key management via environment variables

---

## 🚀 Future Enhancements

- [ ] Mobile app for production floor staff
- [ ] Automated supplier invoice email parsing
- [ ] Multi-branch support for bakery chains
- [ ] Real-time inventory deduction on production log entry
- [ ] PDF export for financial reports and showcases
- [ ] WhatsApp/email alerts for low stock ingredients

---

## 🧑‍💻 Author

**Your Name**
- GitHub: [@saif-dev154](https://github.com/your-username)
- LinkedIn: [https://www.linkedin.com/in/muhammad-saif-ur-rehman-983775341 ](https://linkedin.com/in/your-profile)

---

## 📄 License

This project is licensed under the [MIT License](LICENSE).

---

<div align="center">
  Built with ❤️ using Laravel · MySQL · Bootstrap 5 · Google Vision · GPT-4o
</div>