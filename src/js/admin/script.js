/* global postMigratorAdmin */

import ClipboardJS from 'clipboard';

document.addEventListener('DOMContentLoaded', () => {
	const clipboard = new ClipboardJS('.post-migrator-copy', {
		text: (trigger) => {
			const targetId = trigger.getAttribute('data-copy-target');
			const input = document.getElementById(targetId);

			return input ? input.value : '';
		},
	});

	clipboard.on('success', (event) => {
		const button = event.trigger;
		const originalLabel = button.textContent;
		const copiedLabel =
			'undefined' !== typeof postMigratorAdmin ? postMigratorAdmin.copiedLabel : 'Copied!';

		button.textContent = copiedLabel;

		setTimeout(() => {
			button.textContent = originalLabel;
		}, 1500);

		event.clearSelection();
	});
});
