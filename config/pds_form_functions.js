// Dynamic row add/remove for Annex B tables
function addAnnexRow(tableId, colCount) {
	const tbl = document.getElementById(tableId);
	if (!tbl) return;
	const row = tbl.tBodies[0].insertRow();
	for (let i = 0; i < colCount; i++) {
		const cell = row.insertCell();
		let input = document.createElement('input');
		input.type = (i === 3 || i === 4 || i === 5 || i === 7 || (colCount === 3 && i === 1)) ? 'number' : 'text';
		input.placeholder = '';
		input.title = tbl.tHead.rows[0].cells[i]?.innerText || '';
		input.style.width = '100%';
		cell.appendChild(input);
	}
	// Add remove button
	if (row.cells.length < colCount + 1) {
		const cell = row.insertCell();
		const btn = document.createElement('button');
		btn.type = 'button';
		btn.className = 'rem-btn';
		btn.title = 'Remove row';
		btn.innerText = '✖';
		btn.onclick = function() { removeAnnexRow(btn); };
		cell.appendChild(btn);
	}
}

function removeAnnexRow(btn) {
	const row = btn.closest('tr');
	const tbody = row.parentNode;
	if (tbody.rows.length > 1) tbody.removeChild(row);
	else {
		// Clear inputs if only one row left
		Array.from(row.querySelectorAll('input')).forEach(inp => inp.value = '');
	}
}
