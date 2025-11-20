# Faculty Performance Evaluation System

A comprehensive web-based system for evaluating faculty performance built with PHP, MySQL, HTML, CSS, and JavaScript.

## Features

### Student Portal
- **Evaluate Faculty**: Submit detailed evaluations with multiple criteria ratings
- **Evaluation History**: View previously submitted evaluations
- **Anonymous Evaluations**: Option to submit evaluations anonymously
- **Profile Management**: View and manage student profile information

### Faculty Portal
- **Performance Overview**: View evaluation statistics and ratings
- **Evaluation Details**: Access detailed feedback from students
- **Performance Analytics**: Charts and graphs showing performance trends
- **Criteria Analysis**: Breakdown of ratings by evaluation criteria

### Dean Portal
- **System Analytics**: Comprehensive overview of all faculty performance
- **Department Comparison**: Compare performance across departments
- **Faculty Rankings**: View top and bottom performing faculty
- **Trend Analysis**: Track performance trends over time
- **Criteria Performance**: Analyze which criteria need attention

### Admin Portal
- **User Management**: Add, edit, and delete users (students, faculty, deans)
- **System Overview**: Monitor system usage and statistics
- **Evaluation Criteria**: Manage evaluation criteria and categories
- **Reports**: Generate various system reports
- **Settings**: Configure system parameters

## Technology Stack

- **Backend**: PHP 7.4+
- **Database**: MySQL 5.7+
- **Frontend**: HTML5, CSS3, JavaScript (ES6+)
- **Charts**: Chart.js for data visualization
- **Security**: CSRF protection, password hashing, session management

## Installation

### Prerequisites
- XAMPP (or similar LAMP/WAMP stack)
- PHP 7.4 or higher
- MySQL 5.7 or higher
- Web browser (Chrome, Firefox, Safari, Edge)

### Setup Instructions

1. **Clone/Download the project**
   ```
   Place the project folder in your XAMPP htdocs directory
   Path: C:\xampp\htdocs\1.0fpes\
   ```

2. **Start XAMPP Services**
   - Start Apache
   - Start MySQL

3. **Create Database**
   - Open phpMyAdmin (http://localhost/phpmyadmin)
   - Import the `database.sql` file to create the database and tables
   - Or run the SQL commands manually

4. **Configure Database Connection**
   - Open `config.php`
   - Update database credentials if needed:
     ```php
     define('DB_HOST', 'localhost');
     define('DB_USER', 'root');
     define('DB_PASS', '');
     define('DB_NAME', 'faculty_evaluation_system');
     ```

5. **Access the System**
   - Open your web browser
   - Navigate to: `http://localhost/1.0fpes/`

## Default Login Credentials

The system comes with pre-configured users for testing:

| Role | Username | Password |
|------|----------|----------|
| Admin | admin01 | password |
| Dean | dean01 | password |
| Faculty | faculty01 | password |
| Student | student01 | password |

**Note**: Change these passwords in production!

## Database Schema

### Main Tables
- **users**: Core user information
- **faculty**: Faculty-specific data
- **students**: Student-specific data
- **evaluations**: Evaluation records
- **evaluation_criteria**: Evaluation criteria and categories
- **evaluation_responses**: Detailed ratings for each criterion

### Key Relationships
- Users → Faculty/Students (1:1)
- Faculty → Evaluations (1:many)
- Students → Evaluations (1:many)
- Evaluations → Evaluation Responses (1:many)
- Evaluation Criteria → Evaluation Responses (1:many)

## Security Features

- **Password Hashing**: Uses PHP's `password_hash()` function
- **CSRF Protection**: Tokens for form submissions
- **Session Management**: Secure session handling
- **Input Sanitization**: All user inputs are sanitized
- **Role-based Access Control**: Users can only access appropriate sections
- **SQL Injection Prevention**: Prepared statements used throughout

## File Structure

```
1.0fpes/
├── admin/
│   ├── admin.php          # Admin dashboard
│   └── manage_users.php   # User management backend
├── dean/
│   └── dean.php           # Dean dashboard with analytics
├── faculty/
│   └── faculty.php        # Faculty dashboard
├── student/
│   ├── student.php        # Student dashboard
│   ├── student.css        # Student-specific styles
│   ├── student.js         # Student JavaScript
│   └── submit_evaluation.php # Evaluation submission handler
├── auth.php               # Authentication handler
├── config.php             # Database configuration
├── dashboard.php          # Main dashboard router
├── database.sql           # Database schema
├── index.php              # Login page
├── script.js              # Main JavaScript
├── styles.css             # Main stylesheet
└── README.md              # This file
```

## Evaluation Criteria Categories

The system includes the following evaluation categories:

1. **Teaching Effectiveness**
   - Clarity of Instruction
   - Course Organization
   - Use of Teaching Methods

2. **Student Engagement**
   - Encourages Participation
   - Availability for Help

3. **Assessment**
   - Fair Grading
   - Timely Feedback

4. **Professional Conduct**
   - Punctuality
   - Respect for Students

5. **Course Content**
   - Relevance of Material

## Customization

### Adding New Evaluation Criteria
1. Login as Admin
2. Go to "Evaluation Criteria" section
3. Click "Add New Criterion"
4. Fill in category, criterion name, and description

### Modifying User Roles
Edit the `config.php` file to add new roles or modify existing role permissions.

### Styling Customization
The system uses CSS custom properties (variables) defined in `styles.css`:
- `--primary-color`: Main theme color
- `--secondary-color`: Secondary theme color
- `--danger-color`: Error/warning color
- And more...

## Troubleshooting

### Common Issues

1. **Database Connection Error**
   - Check XAMPP MySQL service is running
   - Verify database credentials in `config.php`
   - Ensure database exists

2. **Login Issues**
   - Check if users table has data
   - Verify password hashing is working
   - Clear browser cache/cookies

3. **Permission Errors**
   - Check file permissions
   - Ensure XAMPP has proper access rights

4. **Charts Not Loading**
   - Check internet connection (Chart.js loads from CDN)
   - Verify JavaScript is enabled in browser

### Error Logs
Check XAMPP error logs:
- Apache: `C:\xampp\apache\logs\error.log`
- PHP: `C:\xampp\php\logs\php_error_log`

## Future Enhancements

- Email notifications for evaluations
- Advanced reporting with PDF export
- Mobile app integration
- Multi-language support
- Advanced analytics with machine learning
- Integration with existing university systems

## Support

For technical support or questions about the system:
1. Check this README file
2. Review the code comments
3. Check XAMPP/PHP error logs
4. Verify database structure matches schema

## License

This project is developed for educational purposes as part of a capstone project.

---

**Note**: This system is designed for educational use. For production deployment, additional security measures and testing should be implemented.
