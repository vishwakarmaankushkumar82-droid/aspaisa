i want to host this php telegram bot on render.com using their free Docker web service. please provide complete configuration files including Docker setup web server rules and required data files. The bot uses users json for storage and needs proper file permissions. You are going to create htaccess Dockerfile composer.json docker-compose.yml error.log index.php and users.json
<?php
// AS-Cinemaa Movie Group Telegram Bot
// Configuration
$BOT_TOKEN = getenv('BOT_TOKEN');
$ADMIN_GROUP_ID = getenv('ADMIN_GROUP_ID');
$GROUP_LINK = getenv('GROUP_LINK');

// Database setup (using SQLite for simplicity)
$db = new SQLite3('bot_data.sqlite');
$db->exec("CREATE TABLE IF NOT EXISTS users (
    user_id INTEGER PRIMARY KEY,
    username TEXT,
    first_name TEXT,
    last_name TEXT,
    subscription_type TEXT,
    subscription_end TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

$db->exec("CREATE TABLE IF NOT EXISTS payments (
    payment_id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER,
    payment_type TEXT,
    amount INTEGER,
    status TEXT DEFAULT 'pending',
    movie_name TEXT,
    admin_message_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");

// Handle incoming update
$update = json_decode(file_get_contents('php://input'), true);
if (!$update) {
    exit;
}

// Extract message data
$message = $update['message'] ?? $update['channel_post'] ?? null;
$callback_query = $update['callback_query'] ?? null;

if ($message) {
    $chat_id = $message['chat']['id'];
    $user_id = $message['from']['id'];
    $first_name = $message['from']['first_name'];
    $last_name = $message['from']['last_name'] ?? '';
    $username = $message['from']['username'] ?? '';
    $text = $message['text'] ?? '';
    $photo = $message['photo'] ?? null;
    
    // Store user info if not exists
    $stmt = $db->prepare("INSERT OR IGNORE INTO users (user_id, username, first_name, last_name) 
                         VALUES (:user_id, :username, :first_name, :last_name)");
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':username', $username, SQLITE3_TEXT);
    $stmt->bindValue(':first_name', $first_name, SQLITE3_TEXT);
    $stmt->bindValue(':last_name', $last_name, SQLITE3_TEXT);
    $stmt->execute();
    
    // Handle commands
    if (strpos($text, '/start') === 0) {
        sendWelcomeMessage($chat_id);
    } 
    // Handle photo (payment proof)
    elseif ($photo) {
        handlePaymentProof($user_id, $chat_id, $message['message_id'], $photo);
    }
    // Handle movie name for pay-per-video
    elseif (isWaitingForMovieName($user_id) && !empty($text)) {
        handleMovieName($user_id, $chat_id, $text);
    }
} 
elseif ($callback_query) {
    $data = $callback_query['data'];
    $user_id = $callback_query['from']['id'];
    $message_id = $callback_query['message']['message_id'];
    $chat_id = $callback_query['message']['chat']['id'];
    
    // Handle callback data
    if (strpos($data, 'subscription_') === 0) {
        $plan = str_replace('subscription_', '', $data);
        handleSubscriptionPlan($user_id, $chat_id, $plan);
    } 
    elseif (strpos($data, 'paypervideo_') === 0) {
        $count = str_replace('paypervideo_', '', $data);
        handlePayPerVideo($user_id, $chat_id, $count);
    }
    // Admin approval actions (from admin group)
    elseif (strpos($data, 'approve_') === 0) {
        $payment_id = str_replace('approve_', '', $data);
        handleAdminApproval($payment_id, $user_id, true);
    } 
    elseif (strpos($data, 'reject_') === 0) {
        $payment_id = str_replace('reject_', '', $data);
        handleAdminApproval($payment_id, $user_id, false);
    }
    
    // Answer callback query
    apiRequest('answerCallbackQuery', [
        'callback_query_id' => $callback_query['id']
    ]);
}

// Function to send welcome message with image
function sendWelcomeMessage($chat_id) {
    $caption = "🎬 AS-Cinemaa Movie Group Premium Plans

🍿 Movies & Web-Series & Cid only

1 Month - ₹15 | 2 Months - ₹30 | 4 Months - ₹50

HD Quality | Unlimited Access | New Releases Added Weekly ✅

💰 Pay-Per-Video Option

Any 1 Video - ₹2 | Any 5 Videos - ₹10

⚡ Why Go Premium?
No Ads | Fast Direct Download | HD Quality | Early Access to New Releases | Web-Series, Movies, Serials & Cartoons – All in One Place

Once confirmed, your Premium Access will be activated instantly! 🎉";
    
    // Send image (replace with your actual image URL or file_id)
    apiRequest('sendPhoto', [
        'chat_id' => $chat_id,
        'photo' => 'https://example.com/welcome_image.jpg',
        'caption' => $caption,
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => 'Buy Subscription', 'callback_data' => 'subscription_menu']
                ],
                [
                    ['text' => 'Pay Per Video', 'callback_data' => 'paypervideo_menu']
                ]
            ]
        ])
    ]);
}

// Handle subscription menu
function handleSubscriptionMenu($user_id, $chat_id) {
    apiRequest('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => getLastMessageId($user_id),
        'text' => 'Select a subscription plan:',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '1 Month ₹15', 'callback_data' => 'subscription_1month']
                ],
                [
                    ['text' => '2 Months ₹30', 'callback_data' => 'subscription_2months']
                ],
                [
                    ['text' => '4 Months ₹50', 'callback_data' => 'subscription_4months']
                ]
            ]
        ])
    ]);
}

// Handle pay per video menu
function handlePayPerVideoMenu($user_id, $chat_id) {
    apiRequest('editMessageText', [
        'chat_id' => $chat_id,
        'message_id' => getLastMessageId($user_id),
        'text' => 'Select a pay-per-video option:',
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '1 Movie ₹2', 'callback_data' => 'paypervideo_1']
                ],
                [
                    ['text' => '2 Movies ₹2', 'callback_data' => 'paypervideo_2']
                ],
                [
                    ['text' => '5 Movies ₹5', 'callback_data' => 'paypervideo_5']
                ]
            ]
        ])
    ]);
}

// Handle subscription plan selection
function handleSubscriptionPlan($user_id, $chat_id, $plan) {
    global $db;
    
    // Map plans to prices and durations
    $plans = [
        '1month' => ['price' => 15, 'duration' => 30],
        '2months' => ['price' => 30, 'duration' => 60],
        '4months' => ['price' => 50, 'duration' => 120]
    ];
    
    if (!isset($plans[$plan])) {
        return;
    }
    
    $price = $plans[$plan]['price'];
    $duration = $plans[$plan]['duration'];
    
    // Store payment in database
    $stmt = $db->prepare("INSERT INTO payments (user_id, payment_type, amount) 
                         VALUES (:user_id, 'subscription', :amount)");
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':amount', $price, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $payment_id = $db->lastInsertRowID();
    
    // Send payment instructions
    apiRequest('sendPhoto', [
        'chat_id' => $chat_id,
        'photo' => 'https://example.com/payment_instructions.jpg',
        'caption' => "Pay ₹{$price} and send screenshot of payment.\n\nUPI ID: example@upi\nBank Transfer: XXXX XXXX XXXX XXXX"
    ]);
}

// Handle pay per video selection
function handlePayPerVideo($user_id, $chat_id, $count) {
    global $db;
    
    $prices = [
        '1' => 2,
        '2' => 2,
        '5' => 5
    ];
    
    if (!isset($prices[$count])) {
        return;
    }
    
    $price = $prices[$count];
    
    // Store payment in database
    $stmt = $db->prepare("INSERT INTO payments (user_id, payment_type, amount) 
                         VALUES (:user_id, 'paypervideo', :amount)");
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $stmt->bindValue(':amount', $price, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $payment_id = $db->lastInsertRowID();
    
    // Set user as waiting for payment proof
    setUserState($user_id, 'waiting_payment_proof');
    
    // Send payment instructions
    apiRequest('sendPhoto', [
        'chat_id' => $chat_id,
        'photo' => 'https://example.com/payment_instructions.jpg',
        'caption' => "Pay ₹{$price} for {$count} movie(s) and send proof of payment.\n\nUPI ID: example@upi\nBank Transfer: XXXX XXXX XXXX XXXX"
    ]);
}

// Handle payment proof (screenshot)
function handlePaymentProof($user_id, $chat_id, $message_id, $photo) {
    global $db, $ADMIN_GROUP_ID;
    
    // Get the largest version of the photo (highest quality)
    $file_id = $photo[count($photo) - 1]['file_id'];
    
    // Check if user has a pending payment
    $stmt = $db->prepare("SELECT payment_id, payment_type FROM payments 
                         WHERE user_id = :user_id AND status = 'pending' 
                         ORDER BY payment_id DESC LIMIT 1");
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $payment = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$payment) {
        apiRequest('sendMessage', [
            'chat_id' => $chat_id,
            'text' => 'No pending payment found. Please select a plan first.'
        ]);
        return;
    }
    
    $payment_id = $payment['payment_id'];
    $payment_type = $payment['payment_type'];
    
    // Forward the photo to admin group
    $forward_result = apiRequest('forwardMessage', [
        'chat_id' => $ADMIN_GROUP_ID,
        'from_chat_id' => $chat_id,
        'message_id' => $message_id
    ]);
    
    $forward_result = json_decode($forward_result, true);
    $admin_message_id = $forward_result['result']['message_id'];
    
    // Update payment with admin message ID
    $stmt = $db->prepare("UPDATE payments SET admin_message_id = :admin_message_id 
                         WHERE payment_id = :payment_id");
    $stmt->bindValue(':admin_message_id', $admin_message_id, SQLITE3_INTEGER);
    $stmt->bindValue(':payment_id', $payment_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    // Send approval buttons to admin group
    $user_info = getUserInfo($user_id);
    $user_text = "User: {$user_info['first_name']}";
    if (!empty($user_info['username'])) {
        $user_text .= " (@{$user_info['username']})";
    }
    
    $payment_text = $payment_type === 'subscription' ? 'Subscription' : 'Pay-Per-Video';
    
    apiRequest('sendMessage', [
        'chat_id' => $ADMIN_GROUP_ID,
        'text' => "Payment proof received from {$user_text}\nType: {$payment_text}\nPayment ID: {$payment_id}",
        'reply_to_message_id' => $admin_message_id,
        'reply_markup' => json_encode([
            'inline_keyboard' => [
                [
                    ['text' => '✅ Approve', 'callback_data' => "approve_{$payment_id}"],
                    ['text' => '❌ Reject', 'callback_data' => "reject_{$payment_id}"]
                ]
            ]
        ])
    ]);
    
    // Notify user
    apiRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => 'Payment proof received. Waiting for admin approval.'
    ]);
    
    // Set user state based on payment type
    if ($payment_type === 'paypervideo') {
        setUserState($user_id, 'waiting_approval_ppv');
    } else {
        setUserState($user_id, 'waiting_approval_sub');
    }
}

// Handle admin approval
function handleAdminApproval($payment_id, $admin_id, $approved) {
    global $db, $GROUP_LINK;
    
    // Get payment details
    $stmt = $db->prepare("SELECT p.*, u.user_id, u.first_name, u.username 
                         FROM payments p 
                         JOIN users u ON p.user_id = u.user_id 
                         WHERE p.payment_id = :payment_id");
    $stmt->bindValue(':payment_id', $payment_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    $payment = $result->fetchArray(SQLITE3_ASSOC);
    
    if (!$payment) {
        return;
    }
    
    $user_id = $payment['user_id'];
    $payment_type = $payment['payment_type'];
    
    if ($approved) {
        // Update payment status
        $stmt = $db->prepare("UPDATE payments SET status = 'approved' 
                             WHERE payment_id = :payment_id");
        $stmt->bindValue(':payment_id', $payment_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        if ($payment_type === 'subscription') {
            // Calculate subscription end date
            $plans = [
                '1month' => 30,
                '2months' => 60,
                '4months' => 120
            ];
            
            $plan = $payment['amount'] == 15 ? '1month' : 
                   ($payment['amount'] == 30 ? '2months' : '4months');
            
            $end_date = date('Y-m-d H:i:s', strtotime("+{$plans[$plan]} days"));
            
            // Update user subscription
            $stmt = $db->prepare("UPDATE users SET subscription_type = :type, 
                                 subscription_end = :end_date 
                                 WHERE user_id = :user_id");
            $stmt->bindValue(':type', $plan, SQLITE3_TEXT);
            $stmt->bindValue(':end_date', $end_date, SQLITE3_TEXT);
            $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
            $stmt->execute();
            
            // Notify user
            apiRequest('sendMessage', [
                'chat_id' => $user_id,
                'text' => "✅ Approved successfully! Here is your group link: {$GROUP_LINK}. Your subscription is valid for {$plans[$plan]} days."
            ]);
        } else {
            // For pay-per-video, set user state to wait for movie name
            setUserState($user_id, 'waiting_movie_name');
            
            // Notify user
            apiRequest('sendMessage', [
                'chat_id' => $user_id,
                'text' => "✅ Approved! Please send the name of the movie you want."
            ]);
        }
        
        // Notify admin
        apiRequest('sendMessage', [
            'chat_id' => $admin_id,
            'text' => "Payment #{$payment_id} approved successfully."
        ]);
    } else {
        // Update payment status
        $stmt = $db->prepare("UPDATE payments SET status = 'rejected' 
                             WHERE payment_id = :payment_id");
        $stmt->bindValue(':payment_id', $payment_id, SQLITE3_INTEGER);
        $stmt->execute();
        
        // Notify user
        apiRequest('sendMessage', [
            'chat_id' => $user_id,
            'text' => "❌ Your payment was rejected. Please contact admin for more information."
        ]);
        
        // Notify admin
        apiRequest('sendMessage', [
            'chat_id' => $admin_id,
            'text' => "Payment #{$payment_id} rejected."
        ]);
    }
}

// Handle movie name for pay-per-video
function handleMovieName($user_id, $chat_id, $movie_name) {
    global $db, $ADMIN_GROUP_ID;
    
    // Update payment with movie name
    $stmt = $db->prepare("UPDATE payments SET movie_name = :movie_name 
                         WHERE user_id = :user_id AND status = 'approved' 
                         AND payment_type = 'paypervideo' 
                         ORDER BY payment_id DESC LIMIT 1");
    $stmt->bindValue(':movie_name', $movie_name, SQLITE3_TEXT);
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $stmt->execute();
    
    // Reset user state
    setUserState($user_id, '');
    
    // Notify admin
    $user_info = getUserInfo($user_id);
    $user_text = "User: {$user_info['first_name']}";
    if (!empty($user_info['username'])) {
        $user_text .= " (@{$user_info['username']})";
    }
    
    apiRequest('sendMessage', [
        'chat_id' => $ADMIN_GROUP_ID,
        'text' => "Movie request from {$user_text}:\n{$movie_name}\n\nPlease send the movie file to the bot when available."
    ]);
    
    // Notify user
    apiRequest('sendMessage', [
        'chat_id' => $chat_id,
        'text' => "Movie request received: {$movie_name}. The admin will send it to you shortly."
    ]);
}

// Helper function to set user state
function setUserState($user_id, $state) {
    // In a real implementation, you would store this in a database
    // For simplicity, we're using a session-like approach with a file
    $states = [];
    if (file_exists('user_states.json')) {
        $states = json_decode(file_get_contents('user_states.json'), true);
    }
    
    $states[$user_id] = $state;
    file_put_contents('user_states.json', json_encode($states));
}

// Helper function to check if user is waiting for movie name
function isWaitingForMovieName($user_id) {
    if (!file_exists('user_states.json')) {
        return false;
    }
    
    $states = json_decode(file_get_contents('user_states.json'), true);
    return isset($states[$user_id]) && $states[$user_id] === 'waiting_movie_name';
}

// Helper function to get user info
function getUserInfo($user_id) {
    global $db;
    
    $stmt = $db->prepare("SELECT first_name, last_name, username FROM users 
                         WHERE user_id = :user_id");
    $stmt->bindValue(':user_id', $user_id, SQLITE3_INTEGER);
    $result = $stmt->execute();
    return $result->fetchArray(SQLITE3_ASSOC);
}

// Helper function to get last message ID for a user
function getLastMessageId($user_id) {
    // In a real implementation, you would store this in a database
    // For simplicity, we're using a session-like approach with a file
    $messages = [];
    if (file_exists('user_messages.json')) {
        $messages = json_decode(file_get_contents('user_messages.json'), true);
    }
    
    return $messages[$user_id] ?? 0;
}

// Helper function to set last message ID for a user
function setLastMessageId($user_id, $message_id) {
    $messages = [];
    if (file_exists('user_messages.json')) {
        $messages = json_decode(file_get_contents('user_messages.json'), true);
    }
    
    $messages[$user_id] = $message_id;
    file_put_contents('user_messages.json', json_encode($messages));
}

// API request function
function apiRequest($method, $params = []) {
    global $BOT_TOKEN;
    
    $url = "https://api.telegram.org/bot{$BOT_TOKEN}/{$method}";
    
    $options = [
        'http' => [
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'method' => 'POST',
            'content' => http_build_query($params)
        ]
    ];
    
    $context = stream_context_create($options);
    $result = file_get_contents($url, false, $context);
    
    return $result;
}

// For testing without webhook
if (php_sapi_name() == 'cli') {
    echo "Bot is running in CLI mode. Use webhook for production.\n";
}
?>
this code uses polling method please make adjustment according to web service in render.com