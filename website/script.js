// Mobile Menu Toggle
function toggleMobileMenu() {
  const navLinks = document.querySelector(".nav-links")
  const mobileBtn = document.querySelector(".mobile-menu-btn")

  const isOpen = navLinks.style.display === "flex"
  navLinks.style.display = isOpen ? "none" : "flex"
  mobileBtn.classList.toggle("active")
  mobileBtn.setAttribute("aria-expanded", !isOpen)
}

// Email Form Submission
document.addEventListener("DOMContentLoaded", function() {
  const waitlistForm = document.getElementById("waitlistForm")
  const formMessage = document.getElementById("formMessage")
  
  if (waitlistForm) {
    waitlistForm.addEventListener("submit", async function(e) {
      e.preventDefault()
      
      const emailInput = document.getElementById("email")
      const submitBtn = waitlistForm.querySelector("button[type='submit']")
      const email = emailInput.value.trim()
      
      // Client-side validation
      if (!email || !validateEmail(email)) {
        showMessage("Please enter a valid email address.", "error")
        return
      }
      
      // Disable form during submission
      submitBtn.disabled = true
      submitBtn.textContent = "Submitting..."
      
      try {
        const response = await fetch("add_email.php", {
          method: "POST",
          headers: {
            "Content-Type": "application/x-www-form-urlencoded",
          },
          body: `email=${encodeURIComponent(email)}`
        })
        
        const data = await response.json()
        
        if (data.success) {
          showMessage(data.message, "success")
          emailInput.value = ""
        } else {
          showMessage(data.message, "error")
        }
      } catch (error) {
        showMessage("An error occurred. Please try again later.", "error")
      } finally {
        submitBtn.disabled = false
        submitBtn.textContent = "Join Newsletter"
      }
    })
  }
})

// Show form message
function showMessage(message, type) {
  const formMessage = document.getElementById("formMessage")
  if (formMessage) {
    formMessage.textContent = message
    formMessage.className = `form-message ${type}`
    formMessage.style.display = "block"
    
    setTimeout(() => {
      formMessage.style.display = "none"
    }, 5000)
  }
}

// FAQ Toggle
function toggleFaq(button) {
  const faqItem = button.parentElement
  const answer = faqItem.querySelector(".faq-answer")

  // Close all other FAQ items
  document.querySelectorAll(".faq-item").forEach((item) => {
    if (item !== faqItem) {
      item.classList.remove("active")
      item.querySelector(".faq-answer").classList.remove("active")
    }
  })

  // Toggle current FAQ item
  faqItem.classList.toggle("active")
  answer.classList.toggle("active")
}

// Smooth Scrolling for Navigation Links
document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
  anchor.addEventListener("click", function (e) {
    e.preventDefault()
    const target = document.querySelector(this.getAttribute("href"))
    if (target) {
      target.scrollIntoView({
        behavior: "smooth",
        block: "start",
      })
    }
  })
})

// Header Background on Scroll
window.addEventListener("scroll", () => {
  const header = document.querySelector(".header")
  if (window.scrollY > 100) {
    header.style.background = "rgba(255, 255, 255, 0.98)"
    header.style.boxShadow = "0 2px 20px rgba(0, 0, 0, 0.1)"
  } else {
    header.style.background = "rgba(255, 255, 255, 0.95)"
    header.style.boxShadow = "none"
  }
})

// Animation on Scroll
function animateOnScroll() {
  const elements = document.querySelectorAll(".feature-card, .spec-item, .pricing-card")

  elements.forEach((element) => {
    const elementTop = element.getBoundingClientRect().top
    const elementVisible = 150

    if (elementTop < window.innerHeight - elementVisible) {
      element.style.opacity = "1"
      element.style.transform = "translateY(0)"
    }
  })
}

// Initialize animations
document.addEventListener("DOMContentLoaded", () => {
  // Set initial state for animated elements
  const elements = document.querySelectorAll(".feature-card, .spec-item, .pricing-card")
  elements.forEach((element) => {
    element.style.opacity = "0"
    element.style.transform = "translateY(30px)"
    element.style.transition = "opacity 0.6s ease, transform 0.6s ease"
  })

  // Run animation check on scroll
  window.addEventListener("scroll", animateOnScroll)

  // Run animation check on load
  animateOnScroll()
})

// Form Validation (if you add a contact form later)
function validateEmail(email) {
  const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/
  return re.test(email)
}

// Add loading states for buttons
document.querySelectorAll(".btn-primary, .btn-secondary").forEach((button) => {
  button.addEventListener("click", function (e) {
    if (this.href && this.href.includes("mailto:")) {
      this.style.opacity = "0.7"
      this.innerHTML = "Opening email..."

      setTimeout(() => {
        this.style.opacity = "1"
        this.innerHTML = this.textContent.includes("Purchase") ? "Purchase for €49" : "Request Demo"
      }, 2000)
    }
  })
})
