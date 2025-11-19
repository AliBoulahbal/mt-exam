# mt-exam
A WordPress plugin for managing exams, students, and results
# Exam Management Plugin  
*A WordPress plugin for academic exam administration and result tracking.*

[![WordPress Plugin](https://img.shields.io/badge/WordPress-6.0%2B-blue)](https://wordpress.org)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-8892BF)](https://www.php.net)

This plugin implements a complete exam management system with custom post types for students, exams, results, and subjects — aligned with the **Applab Senior Developer Screening** requirements.

---
## 📦 Installation

1. Download or clone this repository into your `wp-content/plugins/` directory:
   ```bash
   git clone https://github.com/AliBoulahbal/mt-exam.git

2. In WordPress Admin → Plugins → Installed Plugins, activate Exam Management.
3. Go to the new menu items:<br>
   Students, Subjects, Exams, Results<br>
   Results → Bulk Import<br>
   Students → Statistics Report<br>
Included Files<br>
exam.php — Main plugin file<br>
includes/ — Modular classes (CPTs, meta boxes, AJAX, shortcodes, import, report)<br>
assets/ — CSS for admin (admin.css) and frontend (frontend.css)<br>
sample-results.csv — Example CSV format for bulk import<br>
Sample CSV Format (sample-results.csv)<br>

Testing Instructions<br>
Create a Term: T1 2025-26 (2025-09-01 → 2025-12-20)<br>
Create Subjects: Mathematics, Physics<br>
Create Students: Ali, Mohamed<br>
Create Exam: Midterm (2025-10-15), subjects: Math + Physics, term: T1<br>
Create Results:<br>
Ali: Math=87, Physics=93<br>
Mohamed: Math=76, Physics=84<br>
Verify:
Shortcode [em_top_students] shows Ali #1, Mohamed #2<br>
Statistics Report shows full breakdown<br>
Bulk Import accepts sample-results.csv<br>

