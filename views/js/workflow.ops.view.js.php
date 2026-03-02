<?php

require_once dirname(__FILE__).'/../../../../include/classes/helpers/CCsrfTokenHelper.php';
$wf_labels = [
	'actions' => _('Actions'),
	'close' => _('Close'),
	'chart_expanded' => _('Chart - Last 6 hours before incident'),
	'export_pdf' => _('Export workflow to PDF'),
	'analysis' => _('Incident analysis'),
	'loading' => _('Loading...'),
	'load_error' => _('Failed to load analysis data'),
	'heatmap_hint' => _('Click a cell to filter by day × hour'),
	'low' => _('Low'),
	'high' => _('High'),
	'hint_month' => _('Click a month to see which days had the most incidents'),
	'daily_distribution' => _('Daily distribution'),
	'last_12_months' => _('Last 12 months'),
	'developed_by' => _('Developed by MonZphere'),
	'tags' => _('Tags')
];
$wf_refresh_url = isset($wf_refresh_url) ? $wf_refresh_url : '';
$wf_layout_save_url = isset($wf_layout_save_url) ? $wf_layout_save_url : '';
$wf_analysis_url = isset($wf_analysis_url) ? $wf_analysis_url : '';
$wf_csrf_token = CCsrfTokenHelper::get('item');
?>
jQuery(document).ready(function() {
	var wfLabels = <?= json_encode($wf_labels) ?>;
	var wfRefreshUrl = <?= json_encode($wf_refresh_url) ?>;
	var wfLayoutSaveUrl = <?= json_encode($wf_layout_save_url) ?>;
	var wfAnalysisUrl = <?= json_encode($wf_analysis_url) ?>;

	function renderWorkflowSvg() {
		var flow = document.querySelector('.mnz-workflow-flow-single');
		if (!flow) return;
		var svg = flow.querySelector('.mnz-workflow-svg');
		if (!svg) return;
		var nodes = flow.querySelectorAll('.mnz-workflow-node');
		if (nodes.length < 2) return;

		var flowRect = flow.getBoundingClientRect();
		if (flowRect.width < 1 || flowRect.height < 1) return;

		var linksG = svg.querySelector('g.mnz-workflow-links-group');
		if (!linksG) {
			linksG = document.createElementNS('http://www.w3.org/2000/svg', 'g');
			linksG.setAttribute('class', 'mnz-workflow-links-group');
			svg.appendChild(linksG);
		}

		var edges = [];
		var edgesJson = flow.getAttribute('data-wf-edges');
		if (edgesJson) {
			try {
				edges = JSON.parse(edgesJson);
			} catch (e) {}
		}
		if (edges.length === 0) {
			for (var i = 0; i < nodes.length - 1; i++) {
				edges.push([i, i + 1]);
			}
		}
		var problemPathEdges = {};
		var problemEdgesJson = flow.getAttribute('data-wf-problem-edges');
		if (problemEdgesJson) {
			try {
				var arr = JSON.parse(problemEdgesJson);
				for (var i = 0; i < arr.length; i++) problemPathEdges[arr[i]] = true;
			} catch (e) {}
		}

		function getNodeConnectionPoint(node) {
			var conn = node.querySelector('[data-wf-connector="center"]');
			if (!conn) return null;
			var r = conn.getBoundingClientRect();
			return {
				x: r.left - flowRect.left + r.width / 2,
				y: r.top - flowRect.top + r.height / 2
			};
		}

		var existingLines = linksG.querySelectorAll('line');
		var lineIndex = 0;
		for (var ei = 0; ei < edges.length; ei++) {
			var fromIdx = edges[ei][0];
			var toIdx = edges[ei][1];
			if (fromIdx < 0 || toIdx >= nodes.length || fromIdx >= nodes.length || toIdx < 0) continue;
			var pt1 = getNodeConnectionPoint(nodes[fromIdx]);
			var pt2 = getNodeConnectionPoint(nodes[toIdx]);
			if (!pt1 || !pt2) continue;

			var isProblemPath = problemPathEdges[ei];
			var line = existingLines[lineIndex];
			if (!line) {
				line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
				line.setAttribute('stroke-width', '2');
				line.setAttribute('stroke-linecap', 'round');
				linksG.appendChild(line);
			}
			line.setAttribute('class', 'mnz-workflow-path' + (isProblemPath ? ' mnz-workflow-path-problem' : ''));
			line.setAttribute('x1', pt1.x);
			line.setAttribute('y1', pt1.y);
			line.setAttribute('x2', pt2.x);
			line.setAttribute('y2', pt2.y);
			lineIndex++;
		}
		for (var j = existingLines.length - 1; j >= lineIndex; j--) {
			linksG.removeChild(existingLines[j]);
		}

		svg.setAttribute('width', flowRect.width);
		svg.setAttribute('height', flowRect.height);
		svg.setAttribute('viewBox', '0 0 ' + flowRect.width + ' ' + flowRect.height);
		svg.setAttribute('preserveAspectRatio', 'xMinYMin meet');
	}

	function initWorkflowSvg() {
		var flowSingle = document.querySelector('.mnz-workflow-flow-single');
		if (!flowSingle) return;
		renderWorkflowSvg();
		requestAnimationFrame(renderWorkflowSvg);
		setTimeout(renderWorkflowSvg, 100);
		setTimeout(renderWorkflowSvg, 500);
		if (typeof ResizeObserver !== 'undefined') {
			var ro = new ResizeObserver(renderWorkflowSvg);
			ro.observe(flowSingle);
		}
		window.addEventListener('resize', renderWorkflowSvg);
	}

	function initDraggableNodes() {
		var flowSingle = document.querySelector('.mnz-workflow-flow-single');
		if (!flowSingle) return;
		var nodes = flowSingle.querySelectorAll('.mnz-workflow-node');
		var layoutJson = flowSingle.getAttribute('data-wf-layout');
		var layout = null;
		if (layoutJson) { try { layout = JSON.parse(layoutJson); } catch (e) {} }
		if (layout && layout.nodes && typeof layout.nodes === 'object') {
			nodes.forEach(function(node, i) {
				var pos = layout.nodes[String(i)];
				if (pos && typeof pos.x === 'number' && typeof pos.y === 'number') {
					node.setAttribute('data-drag-x', String(pos.x));
					node.setAttribute('data-drag-y', String(pos.y));
					node.style.transform = 'translate(' + pos.x + 'px, ' + pos.y + 'px)';
				}
			});
		}
		var dragState = null;
		var dragRafId = null;
		function getDragOffset(el) {
			var x = parseFloat(el.getAttribute('data-drag-x')) || 0;
			var y = parseFloat(el.getAttribute('data-drag-y')) || 0;
			return { x: x, y: y };
		}
		function setDragOffset(el, x, y) {
			el.setAttribute('data-drag-x', String(x));
			el.setAttribute('data-drag-y', String(y));
			el.style.transform = 'translate(' + x + 'px, ' + y + 'px)';
		}
		function isInteractiveTarget(target) {
			return target.closest('a, button, [role="button"], input, select, textarea');
		}
		function onMouseDown(e) {
			if (isInteractiveTarget(e.target)) return;
			var node = e.target.closest('.mnz-workflow-node');
			if (!node) return;
			var off = getDragOffset(node);
			dragState = {
				node: node,
				startX: e.clientX,
				startY: e.clientY,
				startOffsetX: off.x,
				startOffsetY: off.y
			};
			node.classList.add('mnz-workflow-dragging');
			flowSingle.classList.add('mnz-workflow-dragging');
			e.preventDefault();
		}
		function onMouseMove(e) {
			if (!dragState) return;
			var dx = e.clientX - dragState.startX;
			var dy = e.clientY - dragState.startY;
			var newX = dragState.startOffsetX + dx;
			var newY = dragState.startOffsetY + dy;
			setDragOffset(dragState.node, newX, newY);
			if (dragRafId == null) {
				dragRafId = requestAnimationFrame(function() {
					dragRafId = null;
					renderWorkflowSvg();
				});
			}
			e.preventDefault();
		}
		function onMouseUp() {
			if (!dragState) return;
			if (dragRafId != null) {
				cancelAnimationFrame(dragRafId);
				dragRafId = null;
			}
			dragState.node.classList.remove('mnz-workflow-dragging');
			flowSingle.classList.remove('mnz-workflow-dragging');
			dragState = null;
			renderWorkflowSvg();
			if (typeof window.mnzWorkflowRenderMinimap === 'function') window.mnzWorkflowRenderMinimap();
			if (typeof window.updateMinimapViewport === 'function') window.updateMinimapViewport();
			if (typeof window.mnzWorkflowSaveLayout === 'function') window.mnzWorkflowSaveLayout();
		}
		nodes.forEach(function(node) {
			node.addEventListener('mousedown', onMouseDown);
		});
		document.addEventListener('mousemove', onMouseMove);
		document.addEventListener('mouseup', onMouseUp);
	}

	function initCanvasPanAndZoom() {
		var canvas = document.querySelector('.mnz-workflow-canvas-single');
		var scrollEl = document.querySelector('.mnz-workflow-canvas-scroll');
		var wrapper = document.querySelector('.mnz-workflow-zoom-wrapper');
		var panTarget = scrollEl || canvas;
		if (!canvas || !panTarget || !wrapper) return;

		var minZoom = 0.5;
		var maxZoom = 2;
		var flow = document.querySelector('.mnz-workflow-flow-single');
		var layoutJson = flow ? flow.getAttribute('data-wf-layout') : null;
		var layout = null;
		if (layoutJson) {
			try { layout = JSON.parse(layoutJson); } catch (e) {}
		}
		var view = {
			panX: (layout && typeof layout.panX === 'number') ? layout.panX : 0,
			panY: (layout && typeof layout.panY === 'number') ? layout.panY : 0,
			zoom: (layout && typeof layout.zoom === 'number') ? Math.max(minZoom, Math.min(maxZoom, layout.zoom)) : 1
		};
		var step = 0.15;
		var panState = null;

		function applyView() {
			wrapper.style.transform = 'translate(' + view.panX + 'px, ' + view.panY + 'px) scale(' + view.zoom + ')';
			if (typeof renderWorkflowSvg === 'function') renderWorkflowSvg();
			if (typeof updateMinimapViewport === 'function') updateMinimapViewport(view);
		}
		function doZoomIn() {
			view.zoom = Math.min(maxZoom, view.zoom + step);
			applyView();
		}
		function doZoomOut() {
			view.zoom = Math.max(minZoom, view.zoom - step);
			applyView();
		}
		function doZoomByDelta(deltaY) {
			var step = deltaY > 0 ? -0.1 : 0.1;
			view.zoom = Math.min(maxZoom, Math.max(minZoom, view.zoom + step));
			applyView();
		}

		function isInteractive(target) {
			return target.closest('a, button, [role="button"], input, select, textarea, .mnz-workflow-minimap');
		}
		function onCanvasMouseDown(e) {
			if (isInteractive(e.target)) return;
			if (e.target.closest('.mnz-workflow-node')) return;
			if (e.target.closest('.mnz-workflow-zoom-toolbar')) return;
			panState = {
				startX: e.clientX,
				startY: e.clientY,
				startPanX: view.panX,
				startPanY: view.panY
			};
			panTarget.style.cursor = 'grabbing';
			panTarget.style.userSelect = 'none';
			e.preventDefault();
		}
		function onCanvasMouseMove(e) {
			if (!panState) return;
			view.panX = panState.startPanX + (e.clientX - panState.startX);
			view.panY = panState.startPanY + (e.clientY - panState.startY);
			applyView();
			e.preventDefault();
		}
		function onCanvasMouseUp() {
			if (!panState) return;
			panState = null;
			panTarget.style.cursor = '';
			panTarget.style.userSelect = '';
		}
		panTarget.addEventListener('mousedown', onCanvasMouseDown);
		document.addEventListener('mousemove', onCanvasMouseMove);
		document.addEventListener('mouseup', onCanvasMouseUp);


		function onZoomWheel(e) {
			if (e.ctrlKey || e.metaKey) {
				e.preventDefault();
				doZoomByDelta(e.deltaY);
			}
		}
		panTarget.addEventListener('wheel', onZoomWheel, { passive: false });
		var minimapEl = document.querySelector('.mnz-workflow-minimap');
		if (minimapEl) minimapEl.addEventListener('wheel', onZoomWheel, { passive: false });

		var toolbar = document.querySelector('.mnz-workflow-zoom-toolbar');
		if (toolbar) {
			var zoomIn = toolbar.querySelector('[data-zoom="in"]');
			var zoomOut = toolbar.querySelector('[data-zoom="out"]');
			if (zoomIn) zoomIn.addEventListener('click', doZoomIn);
			if (zoomOut) zoomOut.addEventListener('click', doZoomOut);
		}

		window.mnzWorkflowView = view;
		applyView();

		window.mnzWorkflowSaveLayout = function() {
			if (!wfLayoutSaveUrl) return;
			var v = window.mnzWorkflowView;
			if (!v) return;
			var flow = document.querySelector('.mnz-workflow-flow-single');
			var nodes = flow ? flow.querySelectorAll('.mnz-workflow-node') : [];
			var nodePos = {};
			nodes.forEach(function(node, i) {
				var x = parseFloat(node.getAttribute('data-drag-x')) || 0;
				var y = parseFloat(node.getAttribute('data-drag-y')) || 0;
				nodePos[i] = { x: x, y: y };
			});
			var layout = { panX: v.panX, panY: v.panY, zoom: v.zoom, nodes: nodePos };
			var payload = { layout: JSON.stringify(layout) };
			payload[typeof CSRF_TOKEN_NAME !== 'undefined' ? CSRF_TOKEN_NAME : 'sid'] = <?= json_encode($wf_csrf_token) ?>;
			fetch(wfLayoutSaveUrl, {
				method: 'POST',
				headers: {'Content-Type': 'application/json'},
				body: JSON.stringify(payload)
			}).catch(function() {});
		};

		var saveLayoutTimer = null;
		function scheduleSaveLayout() {
			if (saveLayoutTimer) clearTimeout(saveLayoutTimer);
			saveLayoutTimer = setTimeout(function() {
				saveLayoutTimer = null;
				if (typeof window.mnzWorkflowSaveLayout === 'function') window.mnzWorkflowSaveLayout();
			}, 600);
		}

		function applyViewWithSave() {
			applyView();
			scheduleSaveLayout();
		}

		view._applyViewWithSave = applyViewWithSave;
	}

	function initMinimap() {
		var minimap = document.querySelector('.mnz-workflow-minimap');
		var flow = document.querySelector('.mnz-workflow-flow-single');
		var scrollEl = document.querySelector('.mnz-workflow-canvas-scroll');
		var wrapper = document.querySelector('.mnz-workflow-zoom-wrapper');
		if (!minimap || !flow || !scrollEl || !wrapper) return;

		var mmW = 140;
		var mmH = 90;
		var padding = 20;

		function getFlowBounds() {
			var nodes = flow.querySelectorAll('.mnz-workflow-node');
			if (nodes.length === 0) return { x: 0, y: 0, w: 400, h: 300 };
			var minX = 1e9, minY = 1e9, maxX = -1e9, maxY = -1e9;
			var flowRect = flow.getBoundingClientRect();
			nodes.forEach(function(node) {
				var r = node.getBoundingClientRect();
				var x = r.left - flowRect.left;
				var y = r.top - flowRect.top;
				minX = Math.min(minX, x);
				minY = Math.min(minY, y);
				maxX = Math.max(maxX, x + r.width);
				maxY = Math.max(maxY, y + r.height);
			});
			var cw = maxX - minX + padding * 2;
			var ch = maxY - minY + padding * 2;
			var w = Math.max(100, cw);
			var h = Math.max(60, ch);
			return { x: minX - padding, y: minY - padding, w: w, h: h };
		}

		function renderMinimapSvg() {
			var bounds = getFlowBounds();
			var nodes = flow.querySelectorAll('.mnz-workflow-node');
			var flowRect = flow.getBoundingClientRect();
			var scaleX = (mmW - 4) / bounds.w;
			var scaleY = (mmH - 4) / bounds.h;
			var scale = Math.min(scaleX, scaleY, 0.25);
			var offX = 2 - bounds.x * scale;
			var offY = 2 - bounds.y * scale;

			var nodesSvg = nodes.length ? Array.prototype.map.call(nodes, function(node) {
				var r = node.getBoundingClientRect();
				var x = r.left - flowRect.left - bounds.x;
				var y = r.top - flowRect.top - bounds.y;
				var w = Math.max(4, r.width * scale);
				var h = Math.max(3, r.height * scale);
				return '<rect x="' + (offX + x * scale) + '" y="' + (offY + y * scale) + '" width="' + w + '" height="' + h + '" rx="2"/>';
			}).join('') : '<rect x="10" y="10" width="20" height="12" rx="2"/>';

			var svg = '<svg class="mnz-workflow-minimap-svg" viewBox="0 0 ' + mmW + ' ' + mmH + '"><g class="mnz-workflow-minimap-nodes">' + nodesSvg + '</g><rect class="mnz-workflow-minimap-viewport" id="mnz-minimap-viewport" width="0" height="0" x="0" y="0"/></svg>';
			minimap.innerHTML = svg;
		}

		window.updateMinimapViewport = function(view) {
			if (!view) view = window.mnzWorkflowView;
			if (!view) return;
			var bounds = getFlowBounds();
			var scrollRect = scrollEl.getBoundingClientRect();
			var scaleX = (mmW - 4) / bounds.w;
			var scaleY = (mmH - 4) / bounds.h;
			var scale = Math.min(scaleX, scaleY, 0.25);
			var offX = 2 - bounds.x * scale;
			var offY = 2 - bounds.y * scale;
			var vpcX = scrollRect.width / 2;
			var vpcY = scrollRect.height / 2;
			var vpLeft = (bounds.x + bounds.w / 2) - (vpcX + view.panX) / view.zoom;
			var vpTop = (bounds.y + bounds.h / 2) - (vpcY + view.panY) / view.zoom;
			var vpWidth = scrollRect.width / view.zoom;
			var vpHeight = scrollRect.height / view.zoom;
			var vx = offX + vpLeft * scale;
			var vy = offY + vpTop * scale;
			var vw = Math.max(4, vpWidth * scale);
			var vh = Math.max(4, vpHeight * scale);
			var vport = minimap.querySelector('#mnz-minimap-viewport');
			if (vport) {
				vport.setAttribute('x', Math.max(0, vx));
				vport.setAttribute('y', Math.max(0, vy));
				vport.setAttribute('width', Math.min(mmW, vw));
				vport.setAttribute('height', Math.min(mmH, vh));
			}
		};

		renderMinimapSvg();
		if (typeof ResizeObserver !== 'undefined') {
			var ro = new ResizeObserver(function() { renderMinimapSvg(); setTimeout(updateMinimapViewport, 50); });
			ro.observe(flow);
		}
		window.addEventListener('resize', function() { renderMinimapSvg(); setTimeout(updateMinimapViewport, 50); });

		minimap.addEventListener('click', function(e) {
			var view = window.mnzWorkflowView;
			if (!view) return;
			var rect = minimap.getBoundingClientRect();
			var bounds = getFlowBounds();
			var scaleX = (mmW - 4) / bounds.w;
			var scaleY = (mmH - 4) / bounds.h;
			var scale = Math.min(scaleX, scaleY, 0.25);
			var offX = 2 - bounds.x * scale;
			var offY = 2 - bounds.y * scale;
			var clickX = (e.clientX - rect.left) / rect.width * mmW;
			var clickY = (e.clientY - rect.top) / rect.height * mmH;
			var mx = (clickX - offX) / scale + bounds.x;
			var my = (clickY - offY) / scale + bounds.y;
			var scrollRect = scrollEl.getBoundingClientRect();
			view.panX = scrollRect.width / 2 - mx * view.zoom;
			view.panY = scrollRect.height / 2 - my * view.zoom;
			var w = document.querySelector('.mnz-workflow-zoom-wrapper');
			if (w) w.style.transform = 'translate(' + view.panX + 'px, ' + view.panY + 'px) scale(' + view.zoom + ')';
			if (typeof renderWorkflowSvg === 'function') renderWorkflowSvg();
			updateMinimapViewport(view);
		});

		window.mnzWorkflowRenderMinimap = renderMinimapSvg;
		setTimeout(function() { renderMinimapSvg(); updateMinimapViewport(); }, 200);
	}

	function initExportPdf() {
		var btn = document.querySelector('.mnz-workflow-export-pdf');
		if (!btn) return;
		btn.addEventListener('click', function() {
			var wrapper = document.querySelector('.mnz-workflow-zoom-wrapper');
			if (!wrapper) return;
			if (typeof html2canvas === 'undefined' || typeof jspdf === 'undefined') {
				alert(wfLabels.export_pdf ? wfLabels.export_pdf + ': ' : '' + 'Libraries loading. Please try again in a moment.');
				return;
			}
			btn.disabled = true;
			html2canvas(wrapper, {
				scale: 2,
				useCORS: true,
				logging: false
			}).then(function(canvas) {
				try {
					var imgData = canvas.toDataURL('image/png');
					var pdf = new jspdf.jsPDF({
						orientation: canvas.width > canvas.height ? 'landscape' : 'portrait',
						unit: 'mm',
						format: 'a4'
					});
					var pageW = pdf.internal.pageSize.getWidth();
					var pageH = pdf.internal.pageSize.getHeight();
					var imgW = pageW - 20;
					var imgH = (canvas.height * imgW) / canvas.width;
					if (imgH > pageH - 20) {
						imgH = pageH - 20;
						imgW = (canvas.width * imgH) / canvas.height;
					}
					pdf.addImage(imgData, 'PNG', 10, 10, imgW, imgH);
					pdf.save('workflow-ops-' + (new Date().toISOString().slice(0, 10)) + '.pdf');
				} catch (e) {
					alert('PDF export failed: ' + (e.message || e));
				}
			}).catch(function(err) {
				alert('Export failed: ' + (err.message || err));
			}).finally(function() {
				btn.disabled = false;
			});
		});
	}

	initWorkflowSvg();
	initDraggableNodes();
	initCanvasPanAndZoom();
	initMinimap();
	initExportPdf();
	window.addEventListener('load', initWorkflowSvg);
	var popId = null;
	function closeActionsPopover() {
		var pop = document.getElementById('mnz-workflow-actions-popover');
		if (pop) {
			pop.classList.remove('mnz-workflow-actions-popover-visible');
			pop.style.display = 'none';
			popId = null;
		}
		jQuery(document).off('keydown.mnzActionsPopover');
	}

	function showActionsPopover(popoverContentId, target) {
		var contentEl = document.getElementById(popoverContentId);
		if (!contentEl) return;
		closeActionsPopover();
		var pop = document.getElementById('mnz-workflow-actions-popover');
		if (!pop) {
			pop = document.createElement('div');
			pop.id = 'mnz-workflow-actions-popover';
			pop.className = 'overlay-dialogue wordbreak mnz-workflow-actions-popover';
			(document.querySelector('.wrapper') || document.body).appendChild(pop);
		}
		var closeLabel = wfLabels.close || 'Close';
		var headerLabel = (target && target.getAttribute && target.getAttribute('data-popover-header')) || wfLabels.actions || 'Actions';
		pop.innerHTML = '<button type="button" class="btn-overlay-close" title="' + closeLabel.replace(/"/g, '&quot;') + '"></button><div class="mnz-workflow-actions-popover-header">' + headerLabel.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div><div class="mnz-workflow-actions-popover-body"></div><div class="mnz-workflow-modal-footer">' + (wfLabels.developed_by || 'Developed by MonZphere') + '</div>';
		var body = pop.querySelector('.mnz-workflow-actions-popover-body');
		body.innerHTML = contentEl.innerHTML;
		jQuery(body).find('table').addClass('list-table');
		var rect = target.getBoundingClientRect();
		pop.style.position = 'fixed';
		pop.style.left = '50%';
		pop.style.top = '50%';
		pop.style.transform = 'translate(-50%, -50%)';
		pop.style.maxWidth = '90vw';
		pop.style.maxHeight = '85vh';
		pop.style.display = 'block';
		pop.classList.add('mnz-workflow-actions-popover-visible');
		popId = popoverContentId;
		jQuery(pop).find('.btn-overlay-close').off('click').on('click', closeActionsPopover);
		jQuery(document).on('keydown.mnzActionsPopover', function(e) {
			if (e.key === 'Escape') closeActionsPopover();
		});
		setTimeout(function() {
			jQuery(document).one('click.mnzActionsPopover', function(e) {
				if (!pop.contains(e.target) && !jQuery(e.target).closest('.mnz-workflow-actions-expand, .mnz-workflow-services-expand').length) {
					closeActionsPopover();
				}
			});
		}, 0);
	}

	jQuery(document).off('click', '.mnz-workflow-actions-expand').on('click', '.mnz-workflow-actions-expand', function(e) {
		e.preventDefault();
		e.stopPropagation();
		var id = jQuery(this).attr('data-popover-id');
		if (id) showActionsPopover(id, this);
	});

	jQuery(document).off('click', '.mnz-workflow-services-expand').on('click', '.mnz-workflow-services-expand', function(e) {
		e.preventDefault();
		e.stopPropagation();
		var id = jQuery(this).attr('data-popover-id');
		if (id) showActionsPopover(id, this);
	});

	var tagsPopoverId = null;
	function closeTagsPopover() {
		var pop = document.getElementById('mnz-workflow-tags-popover');
		if (pop) {
			pop.classList.remove('mnz-workflow-actions-popover-visible');
			pop.style.display = 'none';
			tagsPopoverId = null;
		}
		jQuery(document).off('keydown.mnzTagsPopover');
	}
	function showTagsPopover(content, target) {
		if (!content) return;
		closeTagsPopover();
		var pop = document.getElementById('mnz-workflow-tags-popover');
		if (!pop) {
			pop = document.createElement('div');
			pop.id = 'mnz-workflow-tags-popover';
			pop.className = 'overlay-dialogue wordbreak mnz-workflow-actions-popover';
			(document.querySelector('.wrapper') || document.body).appendChild(pop);
		}
		var closeLabel = wfLabels.close || 'Close';
		var lines = (content || '').split('\n').filter(function(s) { return s.length > 0; });
		var html = '<ul class="mnz-workflow-tags-popover-list">';
		for (var i = 0; i < lines.length; i++) {
			html += '<li>' + jQuery('<div>').text(lines[i]).html() + '</li>';
		}
		html += '</ul>';
		pop.innerHTML = '<button type="button" class="btn-overlay-close" title="' + closeLabel.replace(/"/g, '&quot;') + '"></button><div class="mnz-workflow-actions-popover-header">' + (wfLabels.tags || 'Tags').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div><div class="mnz-workflow-actions-popover-body">' + html + '</div><div class="mnz-workflow-modal-footer">' + (wfLabels.developed_by || 'Developed by MonZphere') + '</div>';
		pop.style.position = 'fixed';
		pop.style.left = '50%';
		pop.style.top = '50%';
		pop.style.transform = 'translate(-50%, -50%)';
		pop.style.maxWidth = '320px';
		pop.style.display = 'block';
		pop.classList.add('mnz-workflow-actions-popover-visible');
		jQuery(pop).find('.btn-overlay-close').off('click').on('click', closeTagsPopover);
		jQuery(document).on('keydown.mnzTagsPopover', function(e) {
			if (e.key === 'Escape') closeTagsPopover();
		});
		setTimeout(function() {
			jQuery(document).one('click.mnzTagsPopover', function(e) {
				if (!pop.contains(e.target) && !jQuery(e.target).closest('.mnz-workflow-tags-trigger').length) {
					closeTagsPopover();
				}
			});
		}, 0);
	}
	jQuery(document).off('click', '.mnz-workflow-tags-trigger').on('click', '.mnz-workflow-tags-trigger', function(e) {
		e.preventDefault();
		e.stopPropagation();
		var content = jQuery(this).attr('data-tags-content');
		showTagsPopover(content, this);
	});
	jQuery(document).off('keydown', '.mnz-workflow-tags-trigger').on('keydown', '.mnz-workflow-tags-trigger', function(e) {
		if (e.key === 'Enter' || e.key === ' ') {
			e.preventDefault();
			var content = jQuery(this).attr('data-tags-content');
			showTagsPopover(content, this);
		}
	});

	jQuery(document).off('click', '.mnz-workflow-item-exec-now').on('click', '.mnz-workflow-item-exec-now', function(e) {
		e.preventDefault();
		var btn = this;
		var itemid = jQuery(btn).attr('data-itemid');
		if (!itemid || typeof window.view === 'undefined' || typeof window.view.executeNow !== 'function') return;
		window.view.executeNow(btn, { itemids: [itemid] });
	});

	var analysisOverlayId = null;
	function closeAnalysisOverlay() {
		var el = document.getElementById('mnz-workflow-analysis-overlay');
		if (el) {
			el.classList.remove('mnz-workflow-actions-popover-visible');
			el.style.display = 'none';
			analysisOverlayId = null;
		}
		jQuery(document).off('keydown.mnzAnalysisOverlay');
	}
	function showAnalysisOverlay(eventid) {
		if (!eventid || !wfAnalysisUrl) return;
		closeAnalysisOverlay();
		var url = wfAnalysisUrl + (wfAnalysisUrl.indexOf('?') >= 0 ? '&' : '?') + 'eventid=' + encodeURIComponent(eventid);
		var pop = document.getElementById('mnz-workflow-analysis-overlay');
		if (!pop) {
			pop = document.createElement('div');
			pop.id = 'mnz-workflow-analysis-overlay';
			pop.className = 'overlay-dialogue wordbreak mnz-workflow-actions-popover mnz-workflow-analysis-overlay';
			(document.querySelector('.wrapper') || document.body).appendChild(pop);
		}
		var closeLabel = wfLabels.close || 'Close';
		var headerLabel = wfLabels.analysis || 'Incident analysis';
		pop.innerHTML = '<button type="button" class="btn-overlay-close" title="' + String(closeLabel).replace(/"/g, '&quot;') + '"></button><div class="mnz-workflow-actions-popover-header">' + String(headerLabel).replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div><div class="mnz-workflow-actions-popover-body"><div class="mnz-workflow-analysis-loading">' + (wfLabels.loading || 'Loading...') + '</div></div><div class="mnz-workflow-modal-footer">' + (wfLabels.developed_by || 'Developed by MonZphere') + '</div>';
		pop.style.position = 'fixed';
		pop.style.left = '50%';
		pop.style.top = '50%';
		pop.style.transform = 'translate(-50%, -50%)';
		pop.style.maxWidth = 'min(92vw, 920px)';
		pop.style.maxHeight = '94vh';
		pop.style.display = 'block';
		pop.classList.add('mnz-workflow-actions-popover-visible');
		analysisOverlayId = eventid;
		jQuery(pop).find('.btn-overlay-close').off('click').on('click', closeAnalysisOverlay);
		jQuery(document).on('keydown.mnzAnalysisOverlay', function(e) {
			if (e.key === 'Escape') closeAnalysisOverlay();
		});
		jQuery.ajax({ url: url, type: 'GET', dataType: 'json' })
			.done(function(d) {
				if (!d || !d.success) {
					jQuery('.mnz-workflow-actions-popover-body', pop).html('<div class="mnz-workflow-card-empty">' + (d && d.error ? d.error : (wfLabels.load_error || 'Failed to load analysis data')) + '</div>');
					return;
				}
				renderAnalysisHeatmap(pop, d);
			})
			.fail(function() {
				jQuery('.mnz-workflow-actions-popover-body', pop).html('<div class="mnz-workflow-card-empty">' + (wfLabels.load_error || 'Failed to load analysis data') + '</div>');
			});
		setTimeout(function() {
			jQuery(document).one('click.mnzAnalysisOverlay', function(e) {
				if (!pop.contains(e.target) && !jQuery(e.target).closest('.mnz-workflow-analysis-expand').length) {
					closeAnalysisOverlay();
				}
			});
		}, 0);
	}
	function renderAnalysisHeatmap(pop, d) {
		var body = pop.querySelector('.mnz-workflow-actions-popover-body');
		if (!body) return;
		var clocks = (d.eventClocks || []).map(function(t) { return parseInt(t, 10); }).filter(function(t) { return !isNaN(t); });
		var monthKeys = d.monthKeys || [];
		var monthLabels = d.monthLabels || monthKeys;
		var dayLabels = (d.dayLabels && d.dayLabels.length) ? d.dayLabels : [];
		while (dayLabels.length < 31) dayLabels.push(dayLabels.length + 1);
		var isDark = document.body.getAttribute('theme') === 'dark-theme' || document.documentElement.getAttribute('theme') === 'dark-theme';
		var barBg = isDark ? '#3a3a3a' : '#e9ecef';
		var barFill = '#0275b8';
		var lc = isDark ? '#999999' : '#666666';

		function computeAggregates(clkList) {
			var wh = []; for (var w = 0; w < 7; w++) { wh[w] = []; for (var h = 0; h < 24; h++) wh[w][h] = 0; }
			var monthly = {}, monthlyDaily = {};
			for (var m = 0; m < monthKeys.length; m++) {
				monthly[monthKeys[m]] = 0;
				monthlyDaily[monthKeys[m]] = [];
				for (var dd = 0; dd < 31; dd++) monthlyDaily[monthKeys[m]][dd] = 0;
			}
			for (var i = 0; i < clkList.length; i++) {
				var dt = new Date(clkList[i] * 1000);
				var h = dt.getHours(), w = dt.getDay();
				var ym = dt.getFullYear() + '-' + (String(dt.getMonth() + 1).padStart(2, '0'));
				var day = dt.getDate() - 1;
				wh[w][h]++;
				if (monthly[ym] !== undefined) {
					monthly[ym]++;
					if (monthlyDaily[ym] && day >= 0 && day < 31) monthlyDaily[ym][day]++;
				}
			}
			var monthVals = [], monthDrill = [];
			for (var m = 0; m < monthKeys.length; m++) {
				var mk = monthKeys[m];
				monthVals.push(monthly[mk] || 0);
				monthDrill.push(monthlyDaily[mk] ? monthlyDaily[mk].slice(0, 31) : []);
			}
			return { weeklyHourlyDetails: wh, monthly: monthVals, monthlyDailyDetails: monthDrill };
		}
		var currentFilter = null;
		var currentAgg = null;
		function getCurrentClocks() {
			if (!currentFilter) return clocks;
			if (currentFilter.type === 'heatmap') return clocks.filter(function(t) { var dt = new Date(t * 1000); return dt.getDay() === currentFilter.weekday && dt.getHours() === currentFilter.hour; });
			if (currentFilter.type === 'month' && currentFilter.value != null) { var mk = monthKeys[currentFilter.value]; return clocks.filter(function(t) { var dt = new Date(t * 1000); return dt.getFullYear() + '-' + (String(dt.getMonth() + 1).padStart(2, '0')) === mk; }); }
			if (currentFilter.type === 'day' && currentFilter.monthIdx != null && currentFilter.day != null) { var mk = monthKeys[currentFilter.monthIdx]; var dateStr = mk + '-' + (String(currentFilter.day).padStart(2, '0')); return clocks.filter(function(t) { var dt = new Date(t * 1000); var ds = dt.getFullYear() + '-' + (String(dt.getMonth() + 1).padStart(2, '0')) + '-' + (String(dt.getDate()).padStart(2, '0')); return ds === dateStr; }); }
			return clocks;
		}
		function getMaintenanceCells(clkList) {
			var cells = {}, m = (d.maintenances || []);
			clkList = clkList || clocks;
			for (var i = 0; i < clkList.length; i++) {
				var t = parseInt(clkList[i], 10);
				if (isNaN(t)) continue;
				for (var j = 0; j < m.length; j++) {
					if (t >= m[j].active_since && t <= m[j].active_till) {
						var dt = new Date(t * 1000);
						var key = dt.getDay() + '-' + dt.getHours();
						if (!cells[key]) cells[key] = [];
						if (cells[key].indexOf(m[j].name) === -1) cells[key].push(m[j].name);
						break;
					}
				}
			}
			return cells;
		}
		function renderMonthly(container, agg) {
			var data = agg.monthly || [];
			var labels = monthLabels;
			var max = Math.max.apply(Math, data); if (max === 0) max = 1;
			var html = '<div class="mnz-monthly-chips">';
			for (var i = 0; i < data.length; i++) {
				var v = data[i];
				var intensity = max > 0 ? v / max : 0;
				var col = v > 0 ? (intensity > 0.5 ? (intensity > 0.8 ? '#c0392b' : '#e67e22') : '#27ae60') : barBg;
				var cls = v > 0 ? ' mnz-monthly-chip-clickable' : '';
				if (v > 0 && i === data.length - 1) col = '#f39c12';
				html += '<div class="mnz-monthly-chip' + cls + '" data-index="' + i + '" title="' + (labels[i] || '') + ': ' + v + '"><span class="mnz-monthly-chip-label">' + (labels[i] || '') + '</span><span class="mnz-monthly-chip-value" style="background:' + col + '">' + v + '</span></div>';
			}
			html += '</div>';
			container.innerHTML = html;
		}
		function renderDrilldown(container, data, labels, title, monthKey) {
			var max = Math.max.apply(Math, data); if (max === 0) max = 1;
			var hint = wfLabels.heatmap_hint || 'Click a day to see hourly distribution in heatmap';
			var html = '<div class="mnz-drilldown-content"><div class="mnz-drilldown-title">' + title + '</div><div class="mnz-drilldown-subhint">' + hint + '</div><div class="mnz-drilldown-chart"><div class="mnz-investigation-bars mnz-drilldown-daily-bars">';
			for (var i = 0; i < data.length; i++) {
				var h = (data[i] / max) * 60;
				var day = i + 1;
				var cls = data[i] > 0 ? ' mnz-drilldown-day-bar mnz-bar-clickable' : '';
				html += '<div class="mnz-investigation-bar-item mnz-drilldown-bar-item' + cls + '" data-day="' + day + '" title="' + labels[i] + ': ' + data[i] + '"><span class="mnz-drilldown-bar-value">' + data[i] + '</span><div class="mnz-investigation-bar" style="height:' + Math.max(h, 4) + 'px;background:' + (data[i] > 0 ? barFill : barBg) + '"></div><span class="mnz-investigation-bar-label">' + labels[i] + '</span></div>';
			}
			html += '</div></div><button type="button" class="btn btn-alt mnz-drilldown-close">' + (wfLabels.close || 'Close') + '</button></div>';
			container.innerHTML = html;
			container.classList.add('mnz-drilldown-visible');
		}
		function renderHeatmap(agg) {
			var wh = agg.weeklyHourlyDetails || [];
			var maxVal = 0;
			for (var w = 0; w < 7; w++) for (var h = 0; h < 24; h++)
				if (wh[w] && wh[w][h] > maxVal) maxVal = wh[w][h];
			if (maxVal === 0) maxVal = 1;
			var maintCells = getMaintenanceCells(getCurrentClocks());
			var hint = wfLabels.heatmap_hint || 'Click a cell to filter by day × hour';
			var html = '<div class="mnz-incident-section-heatmap mnz-workflow-analysis-heatmap"><div class="mnz-drilldown-hint">' + hint + '</div><div id="mnz-workflow-heatmap-inline" class="mnz-heatmap-container"><div class="mnz-heatmap-grid"><div class="mnz-heatmap-labels-col"><div class="mnz-heatmap-corner"></div>';
			for (var w = 0; w < 7; w++) html += '<div class="mnz-heatmap-row-label">' + (d.weekLabels[w] || '') + '</div>';
			html += '</div><div class="mnz-heatmap-body"><div class="mnz-heatmap-hours-row">';
			for (var h = 0; h < 24; h++) html += '<div class="mnz-heatmap-hour-label">' + (d.hourLabels[h] || h) + '</div>';
			html += '</div>';
			for (var w = 0; w < 7; w++) {
				html += '<div class="mnz-heatmap-row">';
				for (var h = 0; h < 24; h++) {
					var v = (wh[w] && wh[w][h]) || 0;
					var intensity = v / maxVal;
					var col = intensity > 0 ? (intensity > 0.5 ? (intensity > 0.8 ? '#c0392b' : '#e67e22') : '#27ae60') : barBg;
					var cls = v > 0 ? ' mnz-heatmap-cell-active' : '';
					var key = w + '-' + h;
					var isMaint = maintCells[key];
					var maintIcon = isMaint ? '<span class="mnz-heatmap-maint-icon ' + (d.maintIconClass || 'zi zi-wrench-alt-small') + '" aria-hidden="true"></span>' : '';
					var maintTip = isMaint ? ' | ' + (d.legendMaintenanceBorder || 'Maintenance') + ': ' + (Array.isArray(isMaint) ? isMaint.join(', ') : isMaint) : '';
					html += '<div class="mnz-heatmap-cell' + cls + '" data-w="' + w + '" data-h="' + h + '" style="background:' + col + '" title="' + (d.weekLabels[w] || '') + ' ' + (d.hourLabels[h] || h) + ': ' + v + maintTip + '"><span class="mnz-heatmap-cell-val">' + v + '</span>' + maintIcon + '</div>';
				}
				html += '</div>';
			}
			var lowLbl = wfLabels.low || 'Low';
			var highLbl = wfLabels.high || 'High';
			html += '</div></div></div><div class="mnz-heatmap-legend"><span class="mnz-heatmap-legend-label">' + lowLbl + '</span><div class="mnz-heatmap-legend-bar"></div><span class="mnz-heatmap-legend-label">' + highLbl + '</span></div></div>';
			return html;
		}
		function applyAndRender() {
			var clk = getCurrentClocks();
			currentAgg = computeAggregates(clk);
			var heatmapHtml = renderHeatmap(currentAgg);
			var monthlyTitle = wfLabels.last_12_months || 'Last 12 months';
			var hintMonth = wfLabels.hint_month || 'Click a month to see which days had the most incidents';
			body.innerHTML = '<div class="mnz-analysis-layout"><div class="mnz-analysis-top-row"><div class="mnz-monthly-section"><h4 class="mnz-incident-chart-title">' + monthlyTitle + '</h4><div id="mnz-workflow-monthly-chart" class="mnz-incident-chart mnz-incident-chart-monthly"></div></div><div class="mnz-heatmap-section">' + heatmapHtml + '</div></div><div id="mnz-workflow-monthly-drilldown" class="mnz-days-section mnz-investigation-drilldown"><div class="mnz-drilldown-hint mnz-drilldown-hint-monthly">' + hintMonth + '</div></div></div>';
			renderMonthly(document.getElementById('mnz-workflow-monthly-chart'), currentAgg);

			jQuery(document).off('click.analysisHeatmap', '#mnz-workflow-analysis-overlay .mnz-heatmap-cell').on('click.analysisHeatmap', '#mnz-workflow-analysis-overlay .mnz-heatmap-cell', function(e) {
				e.stopPropagation();
				var cel = jQuery(this);
				var w = parseInt(cel.attr('data-w'), 10);
				var h = parseInt(cel.attr('data-h'), 10);
				if (isNaN(w) || isNaN(h)) return;
				if (currentFilter && currentFilter.type === 'heatmap' && currentFilter.weekday === w && currentFilter.hour === h) {
					currentFilter = null;
					jQuery('#mnz-workflow-monthly-drilldown').removeClass('mnz-drilldown-visible').empty().append('<div class="mnz-drilldown-hint mnz-drilldown-hint-monthly">' + hintMonth + '</div>');
					applyAndRender();
					return;
				}
				var filtered = clocks.filter(function(t) { var dt = new Date(t * 1000); return dt.getDay() === w && dt.getHours() === h; });
				currentFilter = { type: 'heatmap', weekday: w, hour: h };
				currentAgg = computeAggregates(filtered);
				var hmWrap = body.querySelector('.mnz-heatmap-section');
				if (hmWrap) hmWrap.innerHTML = renderHeatmap(currentAgg);
				renderMonthly(document.getElementById('mnz-workflow-monthly-chart'), currentAgg);
				jQuery('#mnz-workflow-monthly-drilldown').removeClass('mnz-drilldown-visible').empty().append('<div class="mnz-drilldown-hint mnz-drilldown-hint-monthly">' + hintMonth + '</div>');
			});

			jQuery('#mnz-workflow-monthly-chart').off('click', '.mnz-monthly-chip').on('click', '.mnz-monthly-chip', function() {
				var idx = parseInt(jQuery(this).attr('data-index'), 10);
				var mk = monthKeys[idx];
				if (!mk) return;
				if (currentFilter && currentFilter.type === 'month' && currentFilter.value === idx) {
					currentFilter = null;
					jQuery('#mnz-workflow-monthly-drilldown').removeClass('mnz-drilldown-visible').empty().append('<div class="mnz-drilldown-hint mnz-drilldown-hint-monthly">' + hintMonth + '</div>');
					applyAndRender();
					return;
				}
				var filtered = clocks.filter(function(t) { var dt = new Date(t * 1000); return dt.getFullYear() + '-' + (String(dt.getMonth() + 1).padStart(2, '0')) === mk; });
				currentFilter = { type: 'month', value: idx };
				currentAgg = computeAggregates(filtered);
				var hmWrap = body.querySelector('.mnz-heatmap-section');
				if (hmWrap) hmWrap.innerHTML = renderHeatmap(currentAgg);
				renderDrilldown(document.getElementById('mnz-workflow-monthly-drilldown'), currentAgg.monthlyDailyDetails[idx] || [], dayLabels, (wfLabels.daily_distribution || 'Daily distribution') + ' - ' + (monthLabels[idx] || mk), mk);
				jQuery('#mnz-workflow-monthly-drilldown .mnz-drilldown-close').off('click').on('click', function() {
					jQuery('#mnz-workflow-monthly-drilldown').removeClass('mnz-drilldown-visible').empty().append('<div class="mnz-drilldown-hint mnz-drilldown-hint-monthly">' + hintMonth + '</div>');
				});
				jQuery('#mnz-workflow-monthly-drilldown').off('click', '.mnz-drilldown-day-bar').on('click', '.mnz-drilldown-day-bar', function() {
					var day = parseInt(jQuery(this).data('day'), 10);
					var monthIdx = currentFilter.value;
					if (!mk || !day) return;
					if (currentFilter.type === 'day' && currentFilter.monthIdx === monthIdx && currentFilter.day === day) {
						currentFilter = { type: 'month', value: monthIdx };
						currentAgg = computeAggregates(clocks.filter(function(t) { var dt = new Date(t * 1000); return dt.getFullYear() + '-' + (String(dt.getMonth() + 1).padStart(2, '0')) === mk; }));
						var hmWrap = body.querySelector('.mnz-heatmap-section');
						if (hmWrap) hmWrap.innerHTML = renderHeatmap(currentAgg);
						return;
					}
					var dateStr = mk + '-' + (String(day).padStart(2, '0'));
					var dayFiltered = clocks.filter(function(t) { var dt = new Date(t * 1000); var ds = dt.getFullYear() + '-' + (String(dt.getMonth() + 1).padStart(2, '0')) + '-' + (String(dt.getDate()).padStart(2, '0')); return ds === dateStr; });
					var parts = dateStr.split('-');
					var y = parseInt(parts[0], 10), mo = parseInt(parts[1], 10) - 1;
					var sampleDate = new Date(y, mo, day);
					var weekday = sampleDate.getDay();
					var hourly = []; for (var ho = 0; ho < 24; ho++) hourly[ho] = 0;
					for (var i = 0; i < dayFiltered.length; i++) hourly[new Date(dayFiltered[i] * 1000).getHours()]++;
					var wh = []; for (var ww = 0; ww < 7; ww++) { wh[ww] = []; for (var hh = 0; hh < 24; hh++) wh[ww][hh] = 0; }
					wh[weekday] = hourly.slice();
					currentFilter = { type: 'day', monthIdx: monthIdx, day: day, weekday: weekday, dateStr: dateStr };
					currentAgg = { weeklyHourlyDetails: wh, monthly: currentAgg.monthly, monthlyDailyDetails: currentAgg.monthlyDailyDetails };
					var hmWrap = body.querySelector('.mnz-heatmap-section');
					if (hmWrap) hmWrap.innerHTML = renderHeatmap(currentAgg);
				});
			});
		}
		applyAndRender();
	}

	jQuery(document).off('click', '.mnz-workflow-analysis-expand').on('click', '.mnz-workflow-analysis-expand', function(e) {
		e.preventDefault();
		e.stopPropagation();
		var eventid = jQuery(this).attr('data-eventid');
		if (eventid) showAnalysisOverlay(eventid);
	});

	var chartOverlayId = null;
	function closeChartOverlay() {
		var el = document.getElementById('mnz-workflow-chart-overlay');
		if (el) {
			el.classList.remove('mnz-workflow-chart-overlay-visible');
			el.style.display = 'none';
			chartOverlayId = null;
		}
		jQuery(document).off('keydown.mnzChartOverlay');
	}
	function showChartOverlay(url) {
		if (!url) return;
		closeChartOverlay();
		var overlay = document.getElementById('mnz-workflow-chart-overlay');
		if (!overlay) {
			overlay = document.createElement('div');
			overlay.id = 'mnz-workflow-chart-overlay';
			overlay.className = 'overlay-dialogue wordbreak mnz-workflow-chart-overlay';
			(document.querySelector('.wrapper') || document.body).appendChild(overlay);
		}
		var closeLabel = wfLabels.close || 'Close';
		var titleLabel = wfLabels.chart_expanded || 'Chart';
		overlay.innerHTML = '<button type="button" class="btn-overlay-close" title="' + closeLabel.replace(/"/g, '&quot;') + '"></button><div class="mnz-workflow-chart-overlay-header">' + titleLabel.replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</div><div class="mnz-workflow-chart-overlay-body"><img alt="" class="mnz-workflow-chart-expanded" /></div><div class="mnz-workflow-modal-footer">' + (wfLabels.developed_by || 'Developed by MonZphere') + '</div>';
		overlay.querySelector('.mnz-workflow-chart-expanded').src = url;
		overlay.style.position = 'fixed';
		overlay.style.left = '50%';
		overlay.style.top = '50%';
		overlay.style.transform = 'translate(-50%, -50%)';
		overlay.style.maxWidth = '95vw';
		overlay.style.maxHeight = '90vh';
		overlay.style.display = 'block';
		overlay.classList.add('mnz-workflow-chart-overlay-visible');
		jQuery(overlay).find('.btn-overlay-close').off('click').on('click', closeChartOverlay);
		jQuery(document).on('keydown.mnzChartOverlay', function(e) {
			if (e.key === 'Escape') closeChartOverlay();
		});
		setTimeout(function() {
			jQuery(document).one('click.mnzChartOverlay', function(e) {
				if (!overlay.contains(e.target) && !jQuery(e.target).closest('.mnz-workflow-item-spark-wrapper').length) {
					closeChartOverlay();
				}
			});
		}, 0);
	}
	jQuery(document).off('click', '.mnz-workflow-item-spark-wrapper').on('click', '.mnz-workflow-item-spark-wrapper', function(e) {
		e.preventDefault();
		e.stopPropagation();
		var url = jQuery(this).attr('data-chart-url-expanded');
		if (url) showChartOverlay(url);
	});
	jQuery(document).off('keydown', '.mnz-workflow-item-spark-wrapper').on('keydown', '.mnz-workflow-item-spark-wrapper', function(e) {
		if (e.key === 'Enter' || e.key === ' ') {
			e.preventDefault();
			var url = jQuery(this).attr('data-chart-url-expanded');
			if (url) showChartOverlay(url);
		}
	});

	jQuery(document).off('click', '.mnz-workflow-problem-update').on('click', '.mnz-workflow-problem-update', function(e) {
		e.preventDefault();
		var eventid = jQuery(this).attr('data-eventid');
		if (eventid && typeof acknowledgePopUp === 'function') {
			acknowledgePopUp({eventids: [eventid]}, this);
		}
	});

	function wfRefreshContent() {
		if (!wfRefreshUrl) return;
		var el = document.getElementById('mnz-workflow-content');
		if (!el) return;
		el.classList.add('is-loading', 'is-loading-fadein', 'delayed-15s');
		jQuery.ajax({
			url: wfRefreshUrl,
			type: 'GET',
			dataType: 'json'
		})
		.done(function(response) {
			if (response && response.body && el.parentNode) {
				var parsed = new DOMParser().parseFromString(response.body, 'text/html');
				var newEl = parsed.body.firstElementChild;
				if (newEl) el.replaceWith(newEl);
			}
		})
		.always(function() {
			var c = document.getElementById('mnz-workflow-content');
			if (c) c.classList.remove('is-loading', 'is-loading-fadein', 'delayed-15s');
			if (typeof initWorkflowSvg === 'function') initWorkflowSvg();
			if (typeof initDraggableNodes === 'function') initDraggableNodes();
			if (typeof initCanvasPan === 'function') initCanvasPan();
			if (typeof initWorkflowZoom === 'function') initWorkflowZoom();
			if (typeof initExportPdf === 'function') initExportPdf();
			if (typeof renderWorkflowSvg === 'function') {
				renderWorkflowSvg();
				setTimeout(renderWorkflowSvg, 100);
				setTimeout(renderWorkflowSvg, 500);
			}
		});
	}
	window.mnzWorkflowRefresh = wfRefreshContent;

	jQuery.subscribe('acknowledge.create', function() {
		if (window.location.href.indexOf('action=workflow.ops.view') !== -1 && wfRefreshUrl) {
			wfRefreshContent();
		}
	});
});
