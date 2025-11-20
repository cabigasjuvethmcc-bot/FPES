# Departments Admin Structure

This folder contains department-specific admin interfaces and functionality for the Faculty Performance Evaluation System.

## Folder Structure

```
departments/
├── technology/
│   ├── index.php          # Technology department dashboard entry point
│   └── enrollment.php     # Technology student enrollment interface
├── education/
│   ├── index.php          # Education department dashboard entry point
│   └── enrollment.php     # Education student enrollment interface
├── business/
│   ├── index.php          # Business department dashboard entry point
│   └── enrollment.php     # Business student enrollment interface
└── README.md              # This documentation file
```

## Department Admin Access

Each department has its own dedicated admin interface:

### Technology Department
- **Admin Username**: `tech_admin`
- **Password**: `password`
- **Access URL**: `/departments/technology/`

### Education Department
- **Admin Username**: `edu_admin`
- **Password**: `password`
- **Access URL**: `/departments/education/`

### Business Department
- **Admin Username**: `bus_admin`
- **Password**: `password`
- **Access URL**: `/departments/business/`

## Features

- **Department-specific dashboards** with tailored statistics and information
- **Student enrollment management** restricted to each department
- **Access control** ensuring admins can only manage their own department
- **Responsive design** with department-specific color themes

## Security

- Each department folder includes access control to ensure only authorized department admins can access their respective interfaces
- Automatic redirection to main dashboard if unauthorized access is attempted
- Session-based authentication with role and department verification
