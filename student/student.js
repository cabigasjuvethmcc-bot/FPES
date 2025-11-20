// Student Dashboard JavaScript

// Faculty search functionality
function filterFaculty() {
    const searchInput = document.getElementById('faculty_search');
    const facultySelect = document.getElementById('faculty_id');
    const searchTerm = searchInput.value.toLowerCase();
    
    // Get all options except the first one (placeholder)
    const options = Array.from(facultySelect.options).slice(1);
    
    // Hide all options first
    options.forEach(option => {
        option.style.display = 'none';
    });
    
    // Show matching options
    options.forEach(option => {
        const name = option.dataset.name.toLowerCase();
        const department = option.dataset.department.toLowerCase();
        const position = option.dataset.position.toLowerCase();
        
        if (name.includes(searchTerm) || 
            department.includes(searchTerm) || 
            position.includes(searchTerm)) {
            option.style.display = 'block';
        }
    });
    
    // Reset selection if current selection is hidden
    if (facultySelect.selectedIndex > 0 && 
        facultySelect.options[facultySelect.selectedIndex].style.display === 'none') {
        facultySelect.selectedIndex = 0;
    }
}

// Navigation functions
function showSection(sectionName) {
    // Hide all sections
    const sections = document.querySelectorAll('.content-section');
    sections.forEach(section => {
        section.style.display = 'none';
    });
    
    // Show selected section
    const targetSection = document.getElementById(sectionName + '-section');
    if (targetSection) {
        targetSection.style.display = 'block';
    }

    // On small screens, hide sidebar after navigation
    if (window.innerWidth <= 768) {
        const sidebar = document.querySelector('.sidebar');
        if (sidebar && sidebar.classList.contains('active')) {
            sidebar.classList.remove('active');
        }
    }
}

// Logout function
function logout() {
    fetch('../auth.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: 'action=logout'
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            window.location.href = data.redirect;
        }
    })
    .catch(error => {
        window.location.href = '../index.php';
    });
}

// Handle evaluation form submission
  document.addEventListener('DOMContentLoaded', function() {
    const evaluationForm = document.getElementById('evaluation-form');
    const mainContent = document.querySelector('.main-content');
    const enrollmentSelect = document.getElementById('enrollment_select');
    const hiddenFacultyId = document.getElementById('faculty_id');
    const hiddenSubject = document.getElementById('subject');
    const semesterInput = document.getElementById('semester');
    const acadYearInput = document.getElementById('academic_year');

    // Handle flash messages (persisted across reloads)
    const flashMessage = sessionStorage.getItem('flashMessage');
    const flashType = sessionStorage.getItem('flashType'); // 'success' | 'error'
    const flashSection = sessionStorage.getItem('flashSection'); // e.g., 'history'
    if (flashMessage) {
        const messageDiv = document.createElement('div');
        messageDiv.className = flashType === 'success' ? 'success-message' : 'error-message';
        messageDiv.textContent = flashMessage;
        if (mainContent) {
            mainContent.insertBefore(messageDiv, mainContent.firstChild);
        } else if (evaluationForm) {
            evaluationForm.insertBefore(messageDiv, evaluationForm.firstChild);
        }
        // Navigate to target section if provided
        if (flashSection) {
            showSection(flashSection);
        }
        // Clear flash
        sessionStorage.removeItem('flashMessage');
        sessionStorage.removeItem('flashType');
        sessionStorage.removeItem('flashSection');
    }
    
    // Hide already-evaluated options based on selected period
    function updateEnrollmentOptions() {
        if (!enrollmentSelect) return;
        const sem = (semesterInput && semesterInput.value) || '';
        const ay = (acadYearInput && (acadYearInput.value || acadYearInput.getAttribute('value'))) || '';
        const evals = (window.STUDENT_EVALS || []);
        const options = Array.from(enrollmentSelect.options);
        // Skip first placeholder
        options.slice(1).forEach(opt => {
            opt.style.display = '';
            const facultyId = parseInt(opt.getAttribute('data-faculty-id') || '0', 10);
            const subject = opt.getAttribute('data-subject') || '';
            const dup = evals.some(ev => ev.faculty_id === facultyId && ev.subject === subject && (
                (!sem || ev.semester === sem) && (!ay || ev.academic_year === ay)
            ));
            if (dup) {
                opt.style.display = 'none';
                if (enrollmentSelect.value === opt.value) {
                    enrollmentSelect.value = '';
                    hiddenFacultyId.value = '';
                    hiddenSubject.value = '';
                }
            }
        });
    }

    // Wire up enrollment selection to hidden fields used by backend
    if (enrollmentSelect && hiddenFacultyId && hiddenSubject) {
        enrollmentSelect.addEventListener('change', function() {
            const opt = this.options[this.selectedIndex];
            if (opt && opt.value) {
                hiddenFacultyId.value = opt.getAttribute('data-faculty-id') || '';
                hiddenSubject.value = opt.getAttribute('data-subject') || '';
            } else {
                hiddenFacultyId.value = '';
                hiddenSubject.value = '';
            }
        });
        // Trigger initial filtering and react to period changes
        updateEnrollmentOptions();
        // If an option was pre-selected (e.g., via QR quick evaluation), sync hidden fields
        if (enrollmentSelect.value) {
            const opt = enrollmentSelect.options[enrollmentSelect.selectedIndex];
            if (opt) {
                hiddenFacultyId.value = opt.getAttribute('data-faculty-id') || '';
                hiddenSubject.value = opt.getAttribute('data-subject') || '';
            }
        }
        if (semesterInput) semesterInput.addEventListener('change', updateEnrollmentOptions);
        if (acadYearInput) acadYearInput.addEventListener('input', updateEnrollmentOptions);
    }

    if (evaluationForm) {
        evaluationForm.addEventListener('submit', function(e) {
            // Ensure hidden fields are populated based on current selection
            if (enrollmentSelect && enrollmentSelect.value) {
                const opt = enrollmentSelect.options[enrollmentSelect.selectedIndex];
                hiddenFacultyId.value = opt.getAttribute('data-faculty-id') || '';
                hiddenSubject.value = opt.getAttribute('data-subject') || '';
            }

            if (!hiddenFacultyId.value || !hiddenSubject.value) {
                e.preventDefault();
                const existingMessages = document.querySelectorAll('.error-message');
                existingMessages.forEach(msg => msg.remove());
                const msg = document.createElement('div');
                msg.className = 'error-message';
                msg.textContent = 'Please select a subject and faculty.';
                evaluationForm.insertBefore(msg, evaluationForm.firstChild);
                return;
            }
            // otherwise allow normal POST to submit_evaluation.php
        });
    }
    
    // Show evaluate section by default
    // Only set default if not redirected to a specific section via flash
    if (!document.querySelector('.content-section[style*="display: block"]')) {
        showSection('evaluate');
    }
});

const evaluations = JSON.parse(localStorage.getItem("evaluations")) || [];

function renderReports() {
  const tbody = document.querySelector("#reportTable tbody");
  tbody.innerHTML = "";

  evaluations.forEach(ev => {
    const row = `<tr>
      <td>${ev.faculty}</td>
      <td>${ev.q1}</td>
      <td>${ev.q2}</td>
      <td>${ev.q3}</td>
      <td>${ev.comments}</td>
    </tr>`;
    tbody.innerHTML += row;
  });

  const counts = { Excellent: 0, Good: 0, Average: 0, Poor: 0 };
  evaluations.forEach(ev => {
    [ev.q1, ev.q2, ev.q3].forEach(ans => {
      if (counts[ans] !== undefined) counts[ans]++;
    });
  });

  const ctx = document.getElementById("reportChart").getContext("2d");
  if (window.reportChart) window.reportChart.destroy();

  window.reportChart = new Chart(ctx, {
    type: "bar",
    data: {
      labels: Object.keys(counts),
      datasets: [{
        label: "Evaluation Ratings",
        data: Object.values(counts),
        backgroundColor: ["#2ecc71","#3498db","#f39c12","#e74c3c"]
      }]
    },
    options: { responsive: true, plugins: { legend: { display: false } } }
  });
}

async function loadUser() {
  const currentUsername = localStorage.getItem("currentUser");
  if (!currentUsername) return;

  const res = await fetch("http://localhost:3000/users");
  const users = await res.json();
  const user = users.find(u => u.username === currentUsername);

  if (user) {
    document.getElementById("welcome-msg").innerText = 
      `Welcome, ${user.username} (${user.role.toUpperCase()})`;
  }
}
