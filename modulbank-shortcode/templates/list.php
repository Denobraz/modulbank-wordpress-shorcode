<h2><?php _e('Orders', 'fpayments'); ?></h2>
<table class="wp-list-table widefat fixed">
	<thead>
		<tr>
			<th></th>
			<th><?php _e('Date', 'fpayments'); ?></th>
			<th><?php _e('Amount', 'fpayments'); ?></th>
			<th><?php _e('Description', 'fpayments'); ?></th>
			<th><?php _e('Email', 'fpayments'); ?></th>
			<th><?php _e('Name', 'fpayments'); ?></th>
			<th><?php _e('Phone', 'fpayments'); ?></th>
			<th><?php _e('Status', 'fpayments'); ?></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach($rows as $row) { ?>
			<tr>
				<td><?php
					_e('Order #', 'futupayments');
					echo $row['id'];
					if ($row['testing']) {
						_e(' (testing)', 'futupayments');
					}
				?></td>
				<td><?php echo $row['creation_datetime']; ?></td>
				<td><?php echo $row['amount']; ?>&nbsp;<?php echo $row['currency']; ?></td>
				<td><?php echo $row['description']; ?></td>
				<td><?php echo $row['client_email']; ?></td>
				<td><?php echo $row['client_name']; ?></td>
				<td><?php echo $row['client_phone']; ?></td>
				<td><?php echo $statuses[$row['status']]; ?></td>
			</tr>
		<?php } ?>
	</tbody>
</table>

<p>
	<a href="<?php echo $_SERVER['REQUEST_URI'] . (count($_GET) > 0 ? '&' : '?') . 'limit=' . ($limit + $step); ?>"><?php _e('Show more', 'futupayments'); ?></a>
</p>
