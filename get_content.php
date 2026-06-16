<?php
require 'config/database.php';
$stmt = $pdo->prepare('SELECT content FROM posts WHERE slug = ?');
$stmt->execute(['quang-cao-facebook-ads-do-gia-dung-do-dien-tu-dat-khach']);
file_put_contents('post_content.html', $stmt->fetchColumn());
echo "Done";
