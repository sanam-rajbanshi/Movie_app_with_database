<?php
// Database Configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root');
define('DB_PASS', 'sanam@021#'); // Change to your MySQL password
define('DB_NAME', 'workshop_7');
define('API_KEY', 'f3a77333');

// This function connects the databse
function getDBConnection() {
    $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($conn->connect_error) {
        die("Connection failed: " . $conn->connect_error);
    }
    
    return $conn;
}
// Fetch movie details from OMDB API
function getMovieDetails($imdbID) {
    $url = "https://www.omdbapi.com/?i=$imdbID&apikey=" . API_KEY;
    $response = @file_get_contents($url);
    if ($response === FALSE) return null;
    return json_decode($response, true);
}

// Saves movie to database
function saveMovieToDatabase($conn, $movie, $details) {
    $stmt = $conn->prepare("INSERT INTO Movies (title, genre, posterUrl, country, year, type, imdbID) 
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                            title = VALUES(title), 
                            posterUrl = VALUES(posterUrl),
                            year = VALUES(year),
                            type = VALUES(type),
                            genre = VALUES(genre),
                            country = VALUES(country)");
    
    // Check if prepare failed
    if ($stmt === false) {
        error_log("Prepare failed: " . $conn->error);
        return false;
    }
    
    $genre = '';
    $country = '';
    
    if ($details && isset($details['Response']) && $details['Response'] === "True") {
        $genre = $details['Genre'] ?? '';
        $country = $details['Country'] ?? '';
    }
    
    $stmt->bind_param("sssssss", 
        $movie['Title'], 
        $genre, 
        $movie['Poster'], 
        $country, 
        $movie['Year'], 
        $movie['Type'], 
        $movie['imdbID']
    );
    
    $result = $stmt->execute();
    $stmt->close();
    return $result;
}

// Get movies from database
function getMoviesFromDatabase($conn, $search = '') {
    if ($search !== '') {
        $searchTerm = "%$search%";
        $stmt = $conn->prepare("SELECT * FROM Movies WHERE title LIKE ? ORDER BY created_at DESC");
        if ($stmt === false) {
            error_log("Prepare failed: " . $conn->error);
            return [];
        }
        $stmt->bind_param("s", $searchTerm);
    } else {
        $stmt = $conn->prepare("SELECT * FROM Movies ORDER BY created_at DESC");
        if ($stmt === false) {
            error_log("Prepare failed: " . $conn->error);
            return [];
        }
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    $movies = $result->fetch_all(MYSQLI_ASSOC);
    $stmt->close();
    
    return $movies;
}

// Initialize variables
$movies = [];
$error = "";
$success = "";
$conn = getDBConnection();

// Handle search
if (isset($_GET['s']) && $_GET['s'] !== "") {
    $searchQuery = $_GET['s'];
    $search = urlencode($searchQuery);
    $url = "https://www.omdbapi.com/?s=$search&apikey=" . API_KEY;
    $response = @file_get_contents($url);
    
    if ($response === FALSE) {
        $error = "Unable to fetch data from API.";
    } else {
        $data = json_decode($response, true);
        if (isset($data['Response']) && $data['Response'] === "True") {
            $apiMovies = $data['Search'];
            $savedCount = 0;
            
            // Save each movie to database FIRST
            foreach ($apiMovies as $movie) {
                $details = getMovieDetails($movie['imdbID']);
                if (saveMovieToDatabase($conn, $movie, $details)) {
                    $savedCount++;
                }
                // Small delay to avoid API rate limiting
                usleep(100000); // 0.1 second delay
            }
            
            $success = "$savedCount movies saved to database successfully!";
            
            // NOW fetch from database to display
            $movies = getMoviesFromDatabase($conn, $searchQuery);
            
        } else {
            $error = isset($data['Error']) ? $data['Error'] : "Unknown error occurred";
        }
    }
}

$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <title>Movie Search</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px;
            background-color: #f5f5f5;
        }
        h1 {
            color: #333;
            text-align: center;
        }
        h2 {
            color: #555;
            margin-top: 30px;
        }
        #inputData {
            width: 300px;
            padding: 10px;
            font-size: 16px;
            border: 2px solid #ddd;
            border-radius: 4px;
            
        }
        #btn {
            padding: 10px 20px;
            font-size: 16px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 4px;
            cursor: pointer;
        }
        #btn:hover {
            background-color: #0056b3;
        }
        #container {
            display : flex;
            gap : 10px;
            justify-content : center;
            

        }
        .message {
            padding: 10px;
            margin: 15px 0;
            border-radius: 4px;
        }
        .success {
            background-color: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .error {
            background-color: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        table {
            width: 100%;
            background-color: white;
            border-collapse: collapse;
            margin-top: 20px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        th {
            background-color: #007bff;
            color: white;
            padding: 12px;
            text-align: left;
        }
        td {
            padding: 10px;
            border-bottom: 1px solid #ddd;
        }
        tr:hover {
            background-color: #f9f9f9;
        }
    </style>
</head>
<body>
<h1>Movie Search Application</h1>
<div  id="container">
<input type="text" id="inputData" placeholder="Enter movie name" value="<?php echo isset($_GET['s']) ? htmlspecialchars($_GET['s']) : ''; ?>">
<br><br>
<button id="btn">Search</button>
</div>

<?php if ($success): ?>
    <p class="message success"><?php echo $success; ?></p>
<?php endif; ?>

<?php if ($error): ?>
    <p class="message error"><?php echo $error; ?></p>
<?php endif; ?>

<h2>Movies from Database</h2>

<?php if (!empty($movies)): ?>
    <p>Showing <?php echo count($movies); ?> movie(s) from database</p>
    <table>
        <tr>
            <th>ID</th>
            <th>Poster</th>
            <th>Title</th>
            <th>Year</th>
            <th>Type</th>
            <th>Genre</th>
            <th>Country</th>
        </tr>
        <?php foreach ($movies as $movie): ?>
            <tr>
                <td><?php echo $movie['id']; ?></td>
                <td>
                    <?php if ($movie['posterUrl'] && $movie['posterUrl'] !== 'N/A'): ?>
                        <img src="<?php echo htmlspecialchars($movie['posterUrl']); ?>" width="80" alt="Poster">
                    <?php else: ?>
                        No Image
                    <?php endif; ?>
                </td>
                <td><?php echo htmlspecialchars($movie['title']); ?></td>
                <td><?php echo htmlspecialchars($movie['year']); ?></td>
                <td><?php echo htmlspecialchars($movie['type']); ?></td>
                <td><?php echo htmlspecialchars($movie['genre'] ?: 'N/A'); ?></td>
                <td><?php echo htmlspecialchars($movie['country'] ?: 'N/A'); ?></td>
            </tr>
        <?php endforeach; ?>
    </table>
<?php elseif (isset($_GET['s'])): ?>
    <p>No movies found in database for your search.</p>
<?php else: ?>
    <p>Search for movies to see results from database.</p>
<?php endif; ?>

<script>
document.getElementById("btn").addEventListener("click", () => {
  let input_value = document.getElementById("inputData").value.trim();
  if (input_value === "") {
    alert("Please enter the movie name");
    return;
  }
  window.location.href = `index.php?s=${encodeURIComponent(input_value)}`;
});

// Allow Enter key to search
document.getElementById("inputData").addEventListener("keypress", (e) => {
  if (e.key === "Enter") {
    document.getElementById("btn").click();
  }
});
</script>
</body>
</html>