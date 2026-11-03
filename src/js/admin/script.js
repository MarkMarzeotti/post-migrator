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

	initReviewQuickEdit();
	initIncludeUnchangedToggle();
});

/**
 * Toggle a review-step item between its view row and its Quick
 * Edit-style inline-edit row -- purely a display switch, no AJAX save;
 * the actual values submit together when the page's confirm button is
 * clicked, the same as before this was a table.
 */
function initReviewQuickEdit() {
	const table = document.querySelector('.post-migrator-review-table');

	if (!table) {
		return;
	}

	table.addEventListener('click', (event) => {
		const quickEditLink = event.target.closest('.post-migrator-quick-edit');

		if (quickEditLink) {
			event.preventDefault();
			toggleReviewItem(quickEditLink.getAttribute('data-origin-id'), true);
			return;
		}

		const cancelButton = event.target.closest('.post-migrator-quick-edit-cancel');

		if (cancelButton) {
			toggleReviewItem(cancelButton.getAttribute('data-origin-id'), false);
			return;
		}

		const saveButton = event.target.closest('.post-migrator-quick-edit-save');

		if (saveButton) {
			updateReviewSummary(saveButton.getAttribute('data-origin-id'));
			toggleReviewItem(saveButton.getAttribute('data-origin-id'), false);
		}
	});
}

/**
 * Show one item's inline-edit row and hide its view row, or vice versa.
 *
 * @param {string}  originId The item's origin post ID.
 * @param {boolean} showEdit Whether to show the inline-edit row.
 */
function toggleReviewItem(originId, showEdit) {
	const viewRow = document.getElementById('post-migrator-row-' + originId);
	const editRow = document.getElementById('post-migrator-edit-' + originId);

	if (!viewRow || !editRow) {
		return;
	}

	viewRow.classList.toggle('hidden', showEdit);
	editRow.classList.toggle('hidden', !showEdit);
}

/**
 * Wire the Select step's "Include unchanged posts anyway" escape hatch:
 * re-enables (and disables again) the checkboxes of posts flagged
 * unchanged. Turning the toggle back off also unchecks them, so a
 * force-included post never stays silently checked once the override is
 * withdrawn.
 */
function initIncludeUnchangedToggle() {
	const toggle = document.getElementById('post-migrator-include-unchanged');

	if (!toggle) {
		return;
	}

	toggle.addEventListener('change', () => {
		document.querySelectorAll('.post-migrator-cb-unchanged').forEach((checkbox) => {
			checkbox.disabled = !toggle.checked;

			if (!toggle.checked) {
				checkbox.checked = false;
			}
		});
	});
}

/**
 * Refresh an item's view-row summary to reflect whichever item_action
 * radio is currently checked in its inline-edit row.
 *
 * @param {string} originId The item's origin post ID.
 */
function updateReviewSummary(originId) {
	const checkedRadio = document.querySelector(
		'input[name="item_action[' + originId + ']"]:checked'
	);
	const summaryCell = document.querySelector(
		'.post-migrator-summary[data-origin-id="' + originId + '"]'
	);

	if (!checkedRadio || !summaryCell) {
		return;
	}

	const label = checkedRadio.closest('label');

	summaryCell.textContent = label ? label.textContent.trim() : '';
}
