-- Faculty Performance Evaluation System Database Schema
-- Run this script in phpMyAdmin or MySQL command line

CREATE DATABASE IF NOT EXISTS faculty_evaluation_system;
USE faculty_evaluation_system;

-- Users table
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) UNIQUE NOT NULL,
    password VARCHAR(255) NOT NULL,
    role ENUM('student', 'faculty', 'dean', 'admin') NOT NULL,
    full_name VARCHAR(100) NOT NULL,
    email VARCHAR(100),
    department VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Faculty table (extends users for faculty-specific info)
CREATE TABLE faculty (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    employee_id VARCHAR(20) UNIQUE,
    position VARCHAR(50),
    hire_date DATE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Students table (extends users for student-specific info)
CREATE TABLE students (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    student_id VARCHAR(20) UNIQUE,
    year_level VARCHAR(20),
    program VARCHAR(100),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Evaluation criteria
CREATE TABLE evaluation_criteria (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(100) NOT NULL,
    criterion VARCHAR(255) NOT NULL,
    description TEXT,
    weight DECIMAL(3,2) DEFAULT 1.00,
    is_active BOOLEAN DEFAULT TRUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Evaluations table
CREATE TABLE evaluations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    student_id INT NULL,
    faculty_id INT NOT NULL,
    semester VARCHAR(20) NOT NULL,
    academic_year VARCHAR(10) NOT NULL,
    subject VARCHAR(100),
    overall_rating DECIMAL(3,2),
    comments TEXT,
    is_anonymous BOOLEAN DEFAULT TRUE,
    evaluator_user_id INT NULL,
    evaluator_role ENUM('student', 'faculty', 'dean') NULL,
    is_self BOOLEAN DEFAULT FALSE,
    status ENUM('draft', 'submitted', 'reviewed') DEFAULT 'draft',
    submitted_at TIMESTAMP NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (faculty_id) REFERENCES faculty(id) ON DELETE CASCADE,
    FOREIGN KEY (evaluator_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Evaluation responses (detailed ratings for each criterion)
CREATE TABLE evaluation_responses (
    id INT AUTO_INCREMENT PRIMARY KEY,
    evaluation_id INT NOT NULL,
    criterion_id INT NOT NULL,
    rating INT NOT NULL CHECK (rating >= 1 AND rating <= 5),
    comment TEXT,
    FOREIGN KEY (evaluation_id) REFERENCES evaluations(id) ON DELETE CASCADE,
    FOREIGN KEY (criterion_id) REFERENCES evaluation_criteria(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Insert default users
INSERT INTO users (username, password, role, full_name, email, department) VALUES
('admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'System Administrator', 'admin@university.edu', 'IT Department'),
('dean01', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'dean', 'Dr. John Dean', 'dean@university.edu', 'Academic Affairs'),
('faculty01', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'faculty', 'Prof. Jane Smith', 'jsmith@university.edu', 'Computer Science'),
('student01', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'student', 'Alice Johnson', 'alice@student.university.edu', 'Computer Science'),
-- Department Admin Users
('tech_admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'Technology Department Admin', 'tech.admin@university.edu', 'Technology'),
('edu_admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'Education Department Admin', 'edu.admin@university.edu', 'Education'),
('bus_admin', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'admin', 'Business Department Admin', 'bus.admin@university.edu', 'Business');

-- Insert faculty data
INSERT INTO faculty (user_id, employee_id, position, hire_date) VALUES
((SELECT id FROM users WHERE username = 'faculty01'), 'FAC001', 'Associate Professor', '2020-01-15');

-- Insert student data
INSERT INTO students (user_id, student_id, year_level, program) VALUES
((SELECT id FROM users WHERE username = 'student01'), 'STU001', '4th Year', 'Bachelor of Science in Computer Science');

-- Insert default evaluation criteria (aligned with Google Form A–D sections)
INSERT INTO evaluation_criteria (category, criterion, description, weight, is_active) VALUES
-- A. COMMITMENT
('A. COMMITMENT',
 'Demonstrates sensitivity to student''s ability to attend and absorb content information',
 NULL, 1.00, 1),
('A. COMMITMENT',
 'Integrates sensitively her/his learning objectives with those of the students in a collaborative process',
 NULL, 1.00, 1),
('A. COMMITMENT',
 'Makes her/himself available to students beyond official time.',
 NULL, 1.00, 1),
('A. COMMITMENT',
 'Regularly comes to class on time, well-groomed and well-prepared to complete assigned responsibilities',
 NULL, 1.00, 1),
('A. COMMITMENT',
 'Keeps accurate records of student''s performance and prompt submission of the same.',
 NULL, 1.00, 1),

-- B. KNOWLEDGE OF THE SUBJECT
('B. KNOWLEDGE OF THE SUBJECT',
 'Demonstrates mastery of the subject matter (Explain the subject matter without relying solely on the prescribed textbook)',
 NULL, 1.00, 1),
('B. KNOWLEDGE OF THE SUBJECT',
 'Draws and share information on the state on the art of theory and practice in her/his discipline',
 NULL, 1.00, 1),
('B. KNOWLEDGE OF THE SUBJECT',
 'Integrates subjects to practical circumstances and learning intents/purposes of students.',
 NULL, 1.00, 1),
('B. KNOWLEDGE OF THE SUBJECT',
 'Explains the relevance of present topics to the previous lessons, and relates the subject matter to relevant current issues and/or daily life activities.',
 NULL, 1.00, 1),
('B. KNOWLEDGE OF THE SUBJECT',
 'Demonstrates up to date knowledge and/or awareness on current trends and issues of the subject.',
 NULL, 1.00, 1),

-- C. TEACHING FOR INDEPENDENT LEARNING
('C. TEACHING FOR INDEPENDENT LEARNING',
 'Creates teaching strategies that allow students to practice using concepts they need to understand (interactive discussion)',
 NULL, 1.00, 1),
('C. TEACHING FOR INDEPENDENT LEARNING',
 'Enhances students self-esteem and/or gives due recognition to student''s performance/potentials.',
 NULL, 1.00, 1),
('C. TEACHING FOR INDEPENDENT LEARNING',
 'Allows students to create their own course with objectives and realistically defined student-professor rules and make them accountable for their performance',
 NULL, 1.00, 1),
('C. TEACHING FOR INDEPENDENT LEARNING',
 'Allows students to think independently and make their own decisions and holds them accountable for their performance based largely on their success in executing decisions.',
 NULL, 1.00, 1),
('C. TEACHING FOR INDEPENDENT LEARNING',
 'Encourages students to learn beyond what is required and helps/guides the students how to apply the concepts learned.',
 NULL, 1.00, 1),

-- D. MANAGEMENT OF LEARNING
('D. MANAGEMENT OF LEARNING',
 'Creates opportunities for intensive and/or extensive contribution of students in the class activities (e.g. breaks class into dyads, triads or buzz/task groups).',
 NULL, 1.00, 1),
('D. MANAGEMENT OF LEARNING',
 'Drawing students to contribute to knowledge and understanding of the concepts at hand.',
 NULL, 1.00, 1),
('D. MANAGEMENT OF LEARNING',
 'Designs and implements learning conditions and experiences that promote healthy exchange and/or confrontations.',
 NULL, 1.00, 1),
('D. MANAGEMENT OF LEARNING',
 'Structures/re-structures learning and teaching-learning context to enhance attainment of collective learning objectives.',
 NULL, 1.00, 1),
('D. MANAGEMENT OF LEARNING',
 'Use of instructional materials (audio/video materials: fieldtrips, film showing, computer-aided instruction, etc.) to reinforce learning processes.',
 NULL, 1.00, 1);

-- Password reset requests
CREATE TABLE IF NOT EXISTS password_reset_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    identifier VARCHAR(50) NOT NULL,
    role ENUM('Student','Faculty','Dean') NOT NULL,
    status ENUM('Pending','Resolved') DEFAULT 'Pending',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Global Evaluation Schedule (applies to all departments and students)
CREATE TABLE IF NOT EXISTS evaluation_schedule (
    id INT PRIMARY KEY,
    start_at DATETIME NULL,
    end_at DATETIME NULL,
    override_mode ENUM('auto','open','closed') DEFAULT 'auto',
    notice VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
-- Seed singleton row
INSERT INTO evaluation_schedule (id, start_at, end_at, override_mode, notice)
VALUES (1, NULL, NULL, 'auto', NULL)
ON DUPLICATE KEY UPDATE id = id;

-- Student one-time evaluation per faculty+subject+semester+academic_year
-- Note: UNIQUE allows multiple NULLs; dean entries (NULL student_id) will not conflict
CREATE UNIQUE INDEX uniq_student_eval
  ON evaluations (student_id, faculty_id, subject, semester, academic_year);

-- Dean one-time evaluation per faculty+subject+semester+academic_year
CREATE UNIQUE INDEX uniq_dean_eval
  ON evaluations (evaluator_user_id, evaluator_role, faculty_id, subject, semester, academic_year);
