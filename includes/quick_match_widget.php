<?php
// worker/includes/quick_match_widget.php
    if (isset($_SESSION['user_id']) && $_SESSION['role'] === 'worker'): ?>
    <?php
    // Check for active quick matches
    $worker_id = $_SESSION['user_id'];
    $activeMatches = $conn->query("
        SELECT qb.*, qs.*, u.full_name as client_name,
               TIMESTAMPDIFF(MINUTE, NOW(), qs.expires_at) as minutes_left
        FROM quick_match_broadcasts qb
        JOIN quick_match_sessions qs ON qb.session_id = qs.id
        JOIN users u ON qs.client_id = u.id
        WHERE qb.worker_id = $worker_id
        AND qb.status = 'pending'
        AND qs.status = 'matching'
        AND qs.expires_at > NOW()
        ORDER BY qs.urgency_level DESC, qs.created_at ASC
    ");
    
    if ($activeMatches->num_rows > 0):
    ?>
    <div style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); border-radius: 12px; padding: 1rem; margin-bottom: 1rem; color: white;">
        <div style="display: flex; align-items: center; gap: 0.5rem; margin-bottom: 1rem;">
            <i class="fas fa-bolt"></i>
            <h3 style="font-size: 1.1rem;">Active Quick Matches</h3>
        </div>
        
        <?php while ($match = $activeMatches->fetch_assoc()): 
            $urgency_color = $match['urgency_level'] == 'Emergency' ? '#ef4444' : 
                            ($match['urgency_level'] == 'Urgent' ? '#f59e0b' : '#3b82f6');
        ?>
        <div style="background: rgba(255,255,255,0.1); border-radius: 8px; padding: 0.75rem; margin-bottom: 0.5rem;">
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <div>
                    <span style="background: <?php echo $urgency_color; ?>; padding: 0.25rem 0.5rem; border-radius: 4px; font-size: 0.7rem; font-weight: 600;">
                        <?php echo $match['urgency_level']; ?>
                    </span>
                    <span style="margin-left: 0.5rem; font-weight: 500;"><?php echo htmlspecialchars($match['service_category']); ?></span>
                </div>
                <span style="font-size: 0.8rem;"><?php echo $match['minutes_left']; ?> min left</span>
            </div>
            <p style="margin: 0.5rem 0; font-size: 0.9rem;"><?php echo htmlspecialchars(substr($match['problem_desc'], 0, 50)) . '...'; ?></p>
            <div style="display: flex; justify-content: space-between; align-items: center;">
                <span style="font-size: 0.8rem;"><i class="fas fa-user"></i> <?php echo htmlspecialchars($match['client_name']); ?></span>
                <a href="quick_match_view.php?session_id=<?php echo $match['session_id']; ?>" style="background: white; color: #667eea; padding: 0.25rem 1rem; border-radius: 4px; text-decoration: none; font-size: 0.8rem; font-weight: 500;">View →</a>
            </div>
        </div>
        <?php endwhile; ?>
    </div>
    <?php endif; ?>
<?php endif; ?>