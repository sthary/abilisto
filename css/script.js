// js/script.js

document.addEventListener("DOMContentLoaded", function() {
    
    // 1. Confirmation for destructive actions
    // Finds any link with 'delete', 'reject', or 'decline' in the URL and asks for confirmation
    const dangerLinks = document.querySelectorAll("a[href*='delete'], a[href*='reject'], a[href*='Decline']");
    
    dangerLinks.forEach(link => {
        link.addEventListener("click", function(event) {
            const confirmed = confirm("Are you sure you want to proceed with this action?");
            if (!confirmed) {
                event.preventDefault(); // Stop the link from working if they click Cancel
            }
        });
    });

    // 2. Visual Urgency Indicator (For Booking & Quick Match Forms)
    // Changes the border color of the dropdown based on urgency level
    const urgencySelect = document.querySelector("select[name='urgency']");
    if (urgencySelect) {
        urgencySelect.addEventListener("change", function() {
            if (this.value === "Emergency") {
                this.style.borderColor = "red";
                this.style.color = "red";
                this.style.fontWeight = "bold";
            } else if (this.value === "High") {
                this.style.borderColor = "orange";
                this.style.color = "orange";
            } else {
                this.style.borderColor = "#ccc"; // Default gray
                this.style.color = "black";
            }
        });
    }

});