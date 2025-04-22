<?php
// Enable error reporting for debugging
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Check if the user is logged in
if (!isset($_SESSION['user'])) {
    header("Location: login.php");
    exit();
}

// Check if the session ID is set
if (!isset($_SESSION['id'])) {
    die("Session ID not set. Please log in again.");
}

$user = $_SESSION['user'];
$currentUserId = $_SESSION['id'];

// Generate CSRF token if not set
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token");
    }

    session_destroy();
    header("Location: login.php");
    exit();
}

// Database credentials
$host = 'localhost';
$db_username = 'root';
$db_password = '';
$database = 'exam_website';

// Connect to MySQL
$conn = new mysqli($host, $db_username, $db_password, $database);

// Check connection
if ($conn->connect_error) {
    die("Connection failed: " . htmlspecialchars($conn->connect_error));
}

// //TODO: Remove (add to login)
$createProgressTableQuery = "
    CREATE TABLE IF NOT EXISTS student_progress (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        subject VARCHAR(255) NOT NULL,
        question_id INT NOT NULL,
        selected_option CHAR(1),
        completed BOOLEAN DEFAULT 0,
        UNIQUE KEY unique_progress (student_id, subject, question_id)
    )
";
if ($conn->query($createProgressTableQuery) === FALSE) {
    die("Error creating student_progress table: " . htmlspecialchars($conn->error));
}

// //TODO: Remove (add to login)
$createResultsTableQuery = "
    CREATE TABLE IF NOT EXISTS results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        student_id INT NOT NULL,
        subject VARCHAR(255) NOT NULL,
        score INT NOT NULL,
        total_questions INT NOT NULL,
        exam_date DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_result (student_id, subject)
    )
";
if ($conn->query($createResultsTableQuery) === FALSE) {
    die("Error creating results table: " . htmlspecialchars($conn->error));
}

// *** FETCH THE SUBJECT FROM GET OR POST ***
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['subject'])) {
    // Subject is passed via hidden input in form
    $subject = $conn->real_escape_string($_POST['subject']);
} else {
    // Get the subject from the URL
    if (isset($_GET['subject'])) {
        $subject = $conn->real_escape_string($_GET['subject']);
    } else {
        die("No subject selected.");
    }
}

// *** DETECT IF THE STUDENT HAS PREVIOUS ATTEMPT ***
$checkResultQuery = "SELECT * FROM results WHERE student_id = ? AND subject = ?";
$stmt_check = $conn->prepare($checkResultQuery);
if ($stmt_check === false) {
    die("Prepare failed: " . htmlspecialchars($conn->error));
}
$stmt_check->bind_param("is", $currentUserId, $subject);
$stmt_check->execute();
$result_check = $stmt_check->get_result();

$hasPreviousAttempt = ($result_check->num_rows > 0) ? true : false;
$stmt_check->close();

// *** HANDLE QUIZ RETAKE REQUEST ***
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['retake_quiz'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token");
    }

    // Reset student_progress for this subject
    $resetProgressQuery = "DELETE FROM student_progress WHERE student_id = ? AND subject = ?";
    $stmt_reset = $conn->prepare($resetProgressQuery);
    if ($stmt_reset === false) {
        die("Prepare failed: " . htmlspecialchars($conn->error));
    }
    $stmt_reset->bind_param("is", $currentUserId, $subject);
    if (!$stmt_reset->execute()) {
        die("Error resetting progress: " . htmlspecialchars($stmt_reset->error));
    }
    $stmt_reset->close();

    // Delete the previous result
    $deleteResultQuery = "DELETE FROM results WHERE student_id = ? AND subject = ?";
    $stmt_delete = $conn->prepare($deleteResultQuery);
    if ($stmt_delete === false) {
        die("Prepare failed: " . htmlspecialchars($conn->error));
    }
    $stmt_delete->bind_param("is", $currentUserId, $subject);
    if (!$stmt_delete->execute()) {
        die("Error deleting previous result: " . htmlspecialchars($stmt_delete->error));
    }
    $stmt_delete->close();

    // Redirect to the same page to start fresh
    header("Location: exam_page.php?subject=" . urlencode($subject));
    exit();
}

// *** HANDLE EXAM SUBMISSION ***
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_exam'])) {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        die("Invalid CSRF token");
    }

    // Get the subject from POST data
    if (isset($_POST['subject'])) {
        $subject = $conn->real_escape_string($_POST['subject']);
    } else {
        die("No subject specified.");
    }

    // Fetch all answers from student_progress
    $fetchAnswersQuery = "SELECT question_id, selected_option FROM student_progress WHERE student_id = ? AND subject = ?";
    $stmt = $conn->prepare($fetchAnswersQuery);
    if ($stmt === false) {
        die("Prepare failed: " . htmlspecialchars($conn->error));
    }
    $stmt->bind_param("is", $currentUserId, $subject);
    $stmt->execute();
    $answers_result = $stmt->get_result();

    if (!$answers_result) {
        die("Error fetching answers: " . htmlspecialchars($conn->error));
    }

    // Calculate score
    $score = 0;
    $total_questions = 0;

    while ($row = $answers_result->fetch_assoc()) {
        $question_id = $row['question_id'];
        $selected_option = strtoupper($row['selected_option']);

        // Fetch the correct option from quizzes table
        $correctOptionQuery = "SELECT correct_option FROM quizzes WHERE id = ?";
        $stmt_correct = $conn->prepare($correctOptionQuery);
        if ($stmt_correct === false) {
            die("Prepare failed: " . htmlspecialchars($conn->error));
        }
        $stmt_correct->bind_param("i", $question_id);
        $stmt_correct->execute();
        $correct_result = $stmt_correct->get_result();

        if ($correct_result && $correct_result->num_rows > 0) {
            $correct_row = $correct_result->fetch_assoc();
            $correct_option = strtoupper($correct_row['correct_option']);

            if ($selected_option === $correct_option) {
                $score++;
            }
        }
        $stmt_correct->close();
        $total_questions++;
    }

    $stmt->close();

    // Calculate percentage
    if ($total_questions > 0) {
        $percentage = ($score / $total_questions) * 100;
    } else {
        $percentage = 0;
    }

    // Insert into results table
    $insertResultQuery = "
        INSERT INTO results (student_id, subject, score, total_questions)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE score = VALUES(score), total_questions = VALUES(total_questions), exam_date = CURRENT_TIMESTAMP
    ";
    $stmt_insert = $conn->prepare($insertResultQuery);
    if ($stmt_insert === false) {
        die("Prepare failed: " . htmlspecialchars($conn->error));
    }
    $stmt_insert->bind_param("isii", $currentUserId, $subject, $score, $total_questions);
    if (!$stmt_insert->execute()) {
        die("Error inserting results: " . htmlspecialchars($stmt_insert->error));
    }
    $stmt_insert->close();

    // Mark the exam as completed in student_progress
    $markCompletedQuery = "UPDATE student_progress SET completed = 1 WHERE student_id = ? AND subject = ?";
    $stmt_complete = $conn->prepare($markCompletedQuery);
    if ($stmt_complete === false) {
        die("Prepare failed: " . htmlspecialchars($conn->error));
    }
    $stmt_complete->bind_param("is", $currentUserId, $subject);
    if (!$stmt_complete->execute()) {
        die("Error marking exam as completed: " . htmlspecialchars($stmt_complete->error));
    }
    $stmt_complete->close();

    // Redirect to Course Pages (e.g., dashboard.php) with a success message
    header("Location: dashboard.php?message=Exam+submitted+successfully");
    exit();
}

// *** FETCH TOTAL NUMBER OF QUESTIONS FOR THE EXAM ***
$totalQuestionsQuery = $conn->query("SELECT COUNT(*) AS total FROM quizzes WHERE subject='$subject'");
$totalQuestionsRow = $totalQuestionsQuery->fetch_assoc();
$totalQuestions = $totalQuestionsRow['total'];

// *** HANDLE QUESTION NAVIGATION ***
$questionNumber = isset($_GET['q']) ? intval($_GET['q']) : 1;
if ($questionNumber < 1) {
    $questionNumber = 1;
} elseif ($questionNumber > $totalQuestions) {
    $questionNumber = $totalQuestions;
}

// *** FETCH THE CURRENT QUESTION ***
$questionOffset = $questionNumber - 1;
$questionQuery = $conn->query("SELECT * FROM quizzes WHERE subject='$subject' LIMIT $questionOffset, 1");
$question = $questionQuery->fetch_assoc();

// Check if question exists
if (!$question) {
    die("Question not found.");
}

// *** FETCH THE STUDENT'S PREVIOUS ANSWER, IF ANY ***
$answerQuery = $conn->query("SELECT selected_option FROM student_progress WHERE student_id='$currentUserId' AND subject='$subject' AND question_id='{$question['id']}'");
$previousAnswer = strtolower($answerQuery->fetch_assoc()['selected_option'] ?? '');

// *** FETCH ANSWERED QUESTIONS COUNT ***
$answeredQuestionsQuery = $conn->query("SELECT COUNT(*) AS answered FROM student_progress WHERE student_id='$currentUserId' AND subject='$subject' AND selected_option IS NOT NULL");
$answeredQuestionsRow = $answeredQuestionsQuery->fetch_assoc();
$answeredQuestions = $answeredQuestionsRow['answered'];


$conn->close();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <script src="http://ajax.googleapis.com/ajax/libs/jquery/1.9.0/jquery.min.js" type="text/javascript"></script> 
  	<script src="https://cdn.jsdelivr.net/gh/Tezumie/Skulpt-CDN@latest/skulpt.min.js"></script>
  	<script src="https://cdn.jsdelivr.net/gh/Tezumie/Skulpt-CDN@latest/skulpt-stdlib.js"></script>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($subject); ?> Exam - Question <?php echo $questionNumber; ?></title>
    <link rel="stylesheet" href="styles.css">
    <style>
        /* Inline CSS for demonstration; consider moving to styles.css */

        body {
            font-family: Arial, sans-serif;
            background-color: #f4f4f4;
            margin: 0;
            padding: 0;
        }
        .exam-container {
            max-width: 800px;
            margin: 50px auto;
            background-color: #fff;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            position: relative;
        }
        .exit-exam-btn {
            position: absolute;
            top: 10px;
            left: 10px;
            background-color: red;
            color: white;
            padding: 10px;
            border: none;
            cursor: pointer;
            font-size: 16px;
            text-decoration: none;
            border-radius: 4px;
        }
        .exit-exam-btn:hover {
            background-color: darkred;
        }
        h1 {
            margin-top: 0;
            color: #333;
        }
        .exam-question p {
            font-size: 18px;
        }
        .question-options {
            margin: 20px 0;
        }
        .question-options label {
            display: block;
            margin-bottom: 10px;
            font-size: 16px;
        }
        .question-options input[type="radio"] {
            margin-right: 10px;
        }
        .navigation-buttons {
            display: flex;
            justify-content: space-between;
            margin-top: 20px;
        }
        .btn {
            padding: 10px 20px;
            background-color: #007bff;
            border: none;
            border-radius: 5px;
            color: white;
            font-size: 16px;
            cursor: pointer;
            transition: background-color 0.3s;
            text-decoration: none;
            text-align: center;
            display: inline-block;
        }
        .btn:hover {
            background-color: #0056b3;
        }
        .btn:disabled {
            background-color: gray;
            cursor: not-allowed;
        }
        .finish-exam-btn {
            background-color: #28a745;
        }
        .finish-exam-btn:hover {
            background-color: #218838;
        }
        .retake-exam-btn {
            background-color: #ffc107;
        }
        .retake-exam-btn:hover {
            background-color: #e0a800;
        }
        /* AI CHAT TESTING*/
        /* Chat bubble button */
        #chat-bubble {
            position: fixed;
            bottom: 20px;
            right: 20px;
            width: 50px;
            height: 50px;
            background-color: #007bff;
            color: white;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            font-size: 20px;
            box-shadow: 0px 4px 6px rgba(0,0,0,0.1);
        }

        /* Chat window */
        #chat-window {
            position: fixed;
            bottom: 80px;
            right: 20px;
            width: 300px;
            height: 400px;
            background: white;
            border-radius: 10px;
            box-shadow: 0px 4px 10px rgba(0,0,0,0.2);
            display: none;
            flex-direction: column;
            overflow: hidden;
        }

        /* Chat messages */
        #chat-messages {
            flex-grow: 1;
            padding: 10px;
            overflow-y: auto;
            max-height: 350px;
        }

        /* Input field */
        #chat-input {
            border-top: 1px solid #ccc;
            padding: 10px;
            display: flex;
        }

        #chat-input input {
            flex: 1;
            padding: 5px;
            border: none;
            border-radius: 5px;
        }

        #chat-input button {
            margin-left: 5px;
            padding: 5px 10px;
            background: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
        }
         /* AI CHAT TESTING*/
    </style>
    <script>
        // AJAX function to save the selected answer
        function saveAnswer(option) {
            // Disable further changes until the request completes
            const radios = document.getElementsByName('selected_option');
            radios.forEach(radio => radio.disabled = true);

            const xhr = new XMLHttpRequest();
            xhr.open('POST', 'save_answer.php', true); // Ensure 'save_answer.php' handles CSRF tokens
            xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
            xhr.onreadystatechange = function() {
                if (xhr.readyState === XMLHttpRequest.DONE) {
                    // Re-enable radio buttons after the request completes
                    radios.forEach(radio => radio.disabled = false);

                    if (xhr.status === 200) {
                        try {
                            const response = JSON.parse(xhr.responseText);
                            if (response.status === 'success') {
                                console.log('Answer saved successfully.');
                            } else {
                                console.error('Error saving answer:', response.message);
                                alert('Error saving your answer. Please try again.');
                            }
                        } catch (e) {
                            console.error('Invalid JSON response');
                            alert('An unexpected error occurred while saving your answer.');
                        }
                    } else {
                        console.error('AJAX request failed with status:', xhr.status);
                        alert('Failed to save your answer. Please check your connection and try again.');
                    }
                }
            };
            // Send selected option, subject, question_id, student_id, and CSRF token
            xhr.send('save_answer=1&selected_option=' + encodeURIComponent(option) +
                     '&subject=' + encodeURIComponent('<?php echo htmlspecialchars($subject); ?>') +
                     '&question_id=' + encodeURIComponent('<?php echo $question['id']; ?>') +
                     '&student_id=' + encodeURIComponent('<?php echo $currentUserId; ?>') +
                     '&csrf_token=' + encodeURIComponent('<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>'));
        }
    </script>
</head>
<body>
<div class="exam-container">

    <!-- Exit Exam Button -->
    <a href="exams.php" class="exit-exam-btn">Exit Exam</a>

    <h1><?php echo htmlspecialchars($subject); ?> Exam - Question <?php echo $questionNumber; ?></h1>

    <!-- Retake Quiz Button on the First Page Only -->
    <?php if ($hasPreviousAttempt && $questionNumber === 1): ?>
        <form method="POST" style="margin-bottom: 20px;">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
            <input type="hidden" name="subject" value="<?php echo htmlspecialchars($subject); ?>">
            <button type="submit" name="retake_quiz" class="btn retake-exam-btn">Retake Quiz</button>
        </form>
    <?php endif; ?>

    <!-- Main Exam Form -->
    <form method="POST" action="exam_page.php">
        <!-- Include CSRF token -->
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
        <input type="hidden" name="subject" value="<?php echo htmlspecialchars($subject); ?>">

        <div class="exam-question">
            <p><?php echo htmlspecialchars($question['question']); ?></p>

            <div class="question-options">
                <h1>Python Code Instruction Tool</h1>
                <div id="instructions">
                    <h2>Instructions</h2>
                    <p>Develop a general function to take the radius of a circle as input, and return its area:</p>
                    <ul>
                        <li>The math module is already imported for you.</li>
                        <li>Use the code editor below to develop your function.</li>
                        <li>Copy+Paste your function into the code validator below when you have a solution. </li>
                    </ul>
                </div>
                <iframe src="https://trinket.io/embed/python/ce7a0369bfbf?runOption=run" width="100%" height="356" frameborder="0" marginwidth="0" marginheight="0" allowfullscreen></iframe>
                <script type="text/javascript"> 

                var randInt = Math.floor(Math.random()*10);
                console.log("randInt: "+randInt);
                var mypre = "";
                function outf(text) { 
                    mypre = text; 
	                console.log(text);
	                validate();
                }
                function validate(){
	                var checkArea = (Math.PI*(randInt*randInt));
	                console.error(checkArea);
	                console.error(mypre);
	                console.error("difference: "+(Math.abs(Number(checkArea)-Number(mypre))));
	                if (Math.abs(Number(checkArea)-Number(mypre)) < 0.01){
		                document.getElementById("output").innerHTML = "Correct Solution!";
	                }
	                else document.getElementById("output").innerHTML = "Incorrect Solution!";
                } 
                function builtinRead(x) {
                    if (Sk.builtinFiles === undefined || Sk.builtinFiles["files"][x] === undefined)
                    throw "File not found: '" + x + "'";
                return Sk.builtinFiles["files"][x];
                }
                function runit() { 
                    var prog = document.getElementById("yourcode").value;
                    const input = prog;
                    const match = input.match(/^\s*def\s+([a-zA-Z_][a-zA-Z0-9_]*)\s*\(/m);
	                if (match) {
		                console.log(match[1]); // Expected output: circle_area
	                } else {
		                console.log("No function definition found.");
	                }
                    ///	SPECIFIC CHECKS ///	
                    prog += "\nprint("+match[1]+"("+randInt+"))";
                    console.log(prog);
                    ///	SPECIFIC CHECKS ///
   
                    var mypre = document.getElementById("output"); 
                    mypre.innerHTML = ''; 
                    Sk.pre = "output";
                    Sk.configure({output:outf, read:builtinRead}); 
                    (Sk.TurtleGraphics || (Sk.TurtleGraphics = {})).target = 'mycanvas';
                    var myPromise = Sk.misceval.asyncToPromise(function() {
                    return Sk.importMainWithBody("<stdin>", false, prog, true);
                    });
                    myPromise.then(function(mod) {
                    console.log('success');
                    },
                    function(err) {
                        console.log(err.toString());
                    });
                } 
</script> 

<h2>Code Validator</h2>
<form> 
<textarea id="yourcode" cols="40" rows="10"></textarea><br /> 
<button type="button" onclick="runit()">Validate</button> 
</form> 
<pre id="output" ></pre> 
<!-- If you want turtle graphics include a canvas -->
<div id="mycanvas"></div> 
            </div>

            <div class="navigation-buttons">
                <?php if ($questionNumber > 1): ?>
                    <a href="exam_page.php?subject=<?php echo urlencode($subject); ?>&q=<?php echo $questionNumber - 1; ?>" class="btn">Previous Question</a>
                <?php else: ?>
                    <button class="btn" disabled>Previous Question</button>
                <?php endif; ?>

                <?php if ($questionNumber < $totalQuestions): ?>
                    <a href="exam_page.php?subject=<?php echo urlencode($subject); ?>&q=<?php echo $questionNumber + 1; ?>" class="btn">Next Question</a>
                <?php else: ?>
                    <!-- Always enable the Submit Exam button, regardless of answered questions -->
                    <button type="submit" name="submit_exam" class="btn finish-exam-btn">Submit Exam</button>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>
<!-- Chat Bubble -->
<div id="chat-bubble">💬</div>

<!-- Chat Window -->
<div id="chat-window">
    <div id="chat-messages"></div>
    <div id="chat-input">
        <input type="text" id="user-input" placeholder="Type a message...">
        <button onclick="sendMessage()">Send</button>
    </div>
</div>

<script>
    document.getElementById("chat-bubble").addEventListener("click", function() {
        let chatWindow = document.getElementById("chat-window");
        chatWindow.style.display = (chatWindow.style.display === "none" || chatWindow.style.display === "") ? "flex" : "none";
    });

    function sendMessage() {
        let userInput = document.getElementById("user-input").value;
        if (!userInput.trim()) return;

        // Display user message
        let messages = document.getElementById("chat-messages");
        messages.innerHTML += `<div><strong>You:</strong> ${userInput}</div>`;

        // Send user message to backend
        fetch("chat.php", {
        method: "POST",
    headers: { "Content-Type": "application/json" },
    body: JSON.stringify({ message: userInput })
})
.then(response => response.json())
.then(data => {
    if (data.reply) {
        console.log("ChatGPT says:", data.reply);
        document.querySelector("#chat-messages").innerHTML += `<p>\n<strong>AI Teacher: </strong>${data.reply}</p>`;
    } else {
        console.error("Error:", data.error || "Unknown error occurred.");
    }
})
.catch(error => console.error("Fetch error:", error));
    }
</script>
</body>
</html>
