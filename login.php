<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Simple Verification | QueensPa School</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700&display=swap" rel="stylesheet">
</head>
<body class="min-h-screen bg-gradient-to-br from-amber-50 to-orange-50 font-sans flex items-center justify-center p-4" style="font-family: 'Poppins', sans-serif;">
   
    <!-- Main Container -->
    <div class="w-full max-w-md bg-white rounded-2xl shadow-xl overflow-hidden">
       
        <!-- Header -->
        <div class="bg-gradient-to-r from-amber-600 to-orange-600 text-white p-6 text-center">
            <div class="flex items-center justify-center mb-4">
                <div class="w-12 h-12 rounded-xl bg-white/20 flex items-center justify-center mr-3">
                    <i class="fas fa-graduation-cap text-xl"></i>
                </div>
                <h1 class="text-2xl font-bold">QueensPa School</h1>
            </div>
            <p class="text-amber-100">Quick Verification</p>
        </div>
       
        <!-- Content -->
        <div class="p-6">
            <!-- Progress -->
            <div class="flex items-center justify-between mb-8">
                <div class="flex-1">
                    <div class="h-2 bg-gray-200 rounded-full overflow-hidden">
                        <div id="progress-bar" class="h-full bg-amber-500 rounded-full transition-all duration-500" style="width: 25%"></div>
                    </div>
                    <div class="flex justify-between mt-2 text-sm">
                        <span class="text-amber-600 font-medium">Verification</span>
                        <span class="text-gray-400">Login</span>
                    </div>
                </div>
            </div>
           
            <!-- Verification Section -->
            <div id="verification-section">
                <div class="text-center mb-6">
                    <div class="w-16 h-16 rounded-full bg-amber-100 flex items-center justify-center mx-auto mb-4">
                        <i class="fas fa-user-check text-2xl text-amber-600"></i>
                    </div>
                    <h2 class="text-xl font-bold text-gray-800 mb-2">Quick Verification</h2>
                    <p class="text-gray-600 text-sm">Complete one simple step to continue</p>
                </div>
               
                <!-- Simple Checkbox Verification -->
                <div class="mb-6">
                    <div class="bg-gray-50 rounded-lg p-4 border border-gray-200 hover:border-amber-300 transition-colors">
                        <div class="flex items-center">
                            <input type="checkbox" id="human-check" class="h-6 w-6 text-amber-600 rounded focus:ring-amber-500 focus:ring-2 cursor-pointer">
                            <label for="human-check" class="ml-3 text-gray-700 cursor-pointer">
                                <span class="font-medium text-lg">I'm not a robot</span>
                                <span class="text-sm text-gray-500 block mt-1">Check this box to verify you're human</span>
                            </label>
                        </div>
                    </div>
                </div>
               
                <!-- Status Message -->
                <div id="status-message" class="hidden mb-4 p-3 rounded-lg text-sm"></div>
               
                <!-- Continue Button -->
                <button id="continue-button" class="w-full bg-gray-300 text-gray-600 font-semibold py-3 rounded-lg mb-4 cursor-not-allowed transition-all duration-300" disabled>
                    <i class="fas fa-spinner fa-spin mr-2 hidden" id="button-spinner"></i>
                    Continue to Login
                </button>
               
                <!-- Timer -->
                <div class="text-center">
                    <div class="inline-flex items-center space-x-2 text-sm text-gray-600">
                        <i class="fas fa-clock text-amber-500"></i>
                        <span>Verification expires in: </span>
                        <span id="timer" class="font-medium">02:00</span>
                    </div>
                </div>
            </div>
           
            <!-- Loading Section (Hidden) -->
            <div id="loading-section" class="hidden text-center py-8">
                <div class="w-12 h-12 border-3 border-amber-200 border-t-amber-600 rounded-full animate-spin mx-auto mb-4"></div>
                <h3 class="text-lg font-semibold text-gray-800 mb-2">Verifying...</h3>
                <p class="text-gray-600 text-sm">Redirecting to secure login</p>
            </div>
        </div>
       
        <!-- Footer -->
        <div class="border-t border-gray-200 p-4 bg-gray-50">
            <div class="flex items-center justify-center space-x-4 text-xs text-gray-600">
                <span class="flex items-center">
                    <i class="fas fa-lock text-amber-500 mr-1"></i>
                    <span>Simple & Secure</span>
                </span>
                <span>•</span>
                <span>© QueensPa School</span>
            </div>
        </div>
    </div>
   
    <script>
        // Configuration
        const TOTAL_TIME = 120; // 2 minutes in seconds
        
        // State
        let timeLeft = TOTAL_TIME;
        let timerInterval;
        let verificationCompleted = false;
        
        // DOM Elements
        const humanCheck = document.getElementById('human-check');
        const continueButton = document.getElementById('continue-button');
        const statusMessage = document.getElementById('status-message');
        const verificationSection = document.getElementById('verification-section');
        const loadingSection = document.getElementById('loading-section');
        const progressBar = document.getElementById('progress-bar');
        const timerElement = document.getElementById('timer');
        const buttonSpinner = document.getElementById('button-spinner');
        
        // Initialize
        function init() {
            startTimer();
            setupEventListeners();
            
            // Enable checkbox verification by default
            humanCheck.addEventListener('change', () => {
                if (humanCheck.checked) {
                    completeVerification();
                } else {
                    resetVerification();
                }
            });
        }
        
        // Complete verification
        function completeVerification() {
            verificationCompleted = true;
            continueButton.disabled = false;
            continueButton.className = 'w-full bg-gradient-to-r from-amber-500 to-orange-500 text-white font-semibold py-3 rounded-lg mb-4 hover:from-amber-600 hover:to-orange-600 transition-all duration-300 cursor-pointer shadow-md hover:shadow-lg';
            progressBar.style.width = '100%';
            showStatus('✓ Verification complete! You can now continue to login.', 'success');
        }
        
        // Reset verification
        function resetVerification() {
            verificationCompleted = false;
            continueButton.disabled = true;
            continueButton.className = 'w-full bg-gray-300 text-gray-600 font-semibold py-3 rounded-lg mb-4 cursor-not-allowed transition-all duration-300';
            progressBar.style.width = '25%';
            statusMessage.classList.add('hidden');
        }
        
        // Show status message
        function showStatus(message, type = 'error') {
            statusMessage.textContent = message;
            statusMessage.className = `p-3 rounded-lg text-sm ${type === 'error' ? 'bg-red-50 text-red-700 border border-red-200' : 'bg-green-50 text-green-700 border border-green-200'}`;
            statusMessage.classList.remove('hidden');
            
            if (type === 'success') {
                setTimeout(() => statusMessage.classList.add('hidden'), 3000);
            }
        }
        
        // Start timer
        function startTimer() {
            timerInterval = setInterval(() => {
                timeLeft--;
                updateTimerDisplay();
                
                if (timeLeft <= 30) {
                    timerElement.classList.add('text-red-600', 'font-bold');
                }
                
                if (timeLeft <= 0) {
                    clearInterval(timerInterval);
                    showStatus('Verification session expired. Please refresh the page.', 'error');
                    continueButton.disabled = true;
                    humanCheck.disabled = true;
                }
            }, 1000);
        }
        
        // Update timer display
        function updateTimerDisplay() {
            const minutes = Math.floor(timeLeft / 60);
            const seconds = timeLeft % 60;
            timerElement.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
        }
        
        // Setup event listeners
        function setupEventListeners() {
            // Continue button
            continueButton.addEventListener('click', () => {
                if (verificationCompleted) {
                    // Show loading
                    buttonSpinner.classList.remove('hidden');
                    continueButton.disabled = true;
                    
                    setTimeout(() => {
                        verificationSection.classList.add('hidden');
                        loadingSection.classList.remove('hidden');
                        
                        // Redirect after delay
                        setTimeout(() => {
                            window.location.href = 'login_form.php';
                        }, 1500);
                    }, 500);
                }
            });
            
            // Enter key to submit
            document.addEventListener('keypress', (e) => {
                if (e.key === 'Enter' && !continueButton.disabled) {
                    continueButton.click();
                }
            });
            
            // Add slight visual feedback to checkbox container
            const checkboxContainer = humanCheck.closest('.bg-gray-50');
            checkboxContainer.addEventListener('mouseenter', () => {
                if (!humanCheck.disabled) {
                    checkboxContainer.classList.add('bg-amber-50', 'border-amber-400');
                }
            });
            
            checkboxContainer.addEventListener('mouseleave', () => {
                checkboxContainer.classList.remove('bg-amber-50', 'border-amber-400');
            });
        }
        
        // Initialize on load
        document.addEventListener('DOMContentLoaded', init);
    </script>
   
    <style>
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        #verification-section {
            animation: fadeIn 0.5s ease-out;
        }
        
        .cursor-pointer {
            cursor: pointer;
        }
        
        .transition-all {
            transition-property: all;
            transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
            transition-duration: 200ms;
        }
        
        .transition-colors {
            transition-property: background-color, border-color, color, fill, stroke;
            transition-timing-function: cubic-bezier(0.4, 0, 0.2, 1);
            transition-duration: 200ms;
        }
        
        .shadow-md {
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
        }
        
        .hover\:shadow-lg:hover {
            box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
        }
    </style>
</body>
</html>