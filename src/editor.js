// The editor page: GridLogic runs in an iframe on this origin (`?host=parent&ack=1`). This page
// reads the file over WebDAV with the user's session, hands it to GridLogic, and writes back
// what GridLogic saves. Protocol: gridcraft/packaging/web/README.md.
//
// The same page serves shared links (`/s/{token}`, `data-share-token`): the file is then the
// link's own WebDAV address, and the person is a guest under the name they gave Nextcloud.

import { getCurrentUser, getGuestNickname, getRequestToken } from '@nextcloud/auth'
import { generateRemoteUrl, generateUrl, getRootUrl } from '@nextcloud/router'

import { Collab, colorFor } from './collab.js'
import { guestSharing, sharing } from './share.js'

const root = document.getElementById('gridcraft-app')
const status = document.getElementById('gridcraft-status')
const path = root?.dataset.path ?? ''
const fileId = Number(root?.dataset.fileId ?? 0)
/** A shared link's token: the page is the link's. */
const token = root?.dataset.shareToken ?? ''
const viaLink = token !== ''
/** Whether the link lets people edit (signed-in users: Nextcloud decides on each save). */
const linkCanEdit = root?.dataset.canEdit === '1'
const user = getCurrentUser()
/** How the others see this person. */
const personName = user?.displayName || user?.uid || getGuestNickname() || 'Guest'

/** The folder of `path` and its file name. */
function split(p) {
	const i = p.lastIndexOf('/')
	return i < 0 ? ['', p] : [p.slice(0, i), p.slice(i + 1)]
}

const [folder, fileName] = viaLink ? ['', root?.dataset.fileName ?? ''] : split(path)

/** WebDAV URL of a path in the user's files; through a link, the shared file's. */
function davUrl(p) {
	if (viaLink) {
		return `${getRootUrl()}/public.php/dav/files/${encodeURIComponent(token)}`
	}
	const encoded = p.split('/').filter(Boolean).map(encodeURIComponent).join('/')
	return `${generateRemoteUrl(`dav/files/${encodeURIComponent(user.uid)}`)}/${encoded}`
}

function show(text, error = false) {
	status.textContent = text
	status.classList.toggle('error', error)
	status.hidden = false
}

/** Known ETags by path: the file opened, and files this page created. */
const etags = new Map()
let dirty = false
/** The workbook's current name: an imported .csv/.ods becomes .xlsx once saved. */
let shownName = fileName

/** The co-editing session, once joined. */
let collab = null
/** Carries out the Share dialog's requests (made on first use). */
let shareHandler = null
/** Who else is in it. */
let others = 0

function showTitle() {
	const unsaved = collab ? collab.unsaved : dirty
	const company = others > 0 ? ` (${others + 1} editing)` : ''
	document.title = `${unsaved ? '• ' : ''}${shownName}${company} - GridLogic`
}

/** A message over the top of the editor, not covering it (co-editing). */
function notify(text, error = false) {
	let bar = document.getElementById('gridcraft-notice')
	if (!bar) {
		bar = document.createElement('div')
		bar.id = 'gridcraft-notice'
		root.prepend(bar)
	}
	bar.textContent = text
	bar.classList.toggle('error', error)
	clearTimeout(bar.timer)
	bar.timer = setTimeout(() => bar.remove(), error ? 15000 : 5000)
}

function headers(extra = {}) {
	// X-Requested-With: a link's WebDAV answers a missing password with 401, not a login prompt.
	return { requesttoken: getRequestToken() ?? '', 'X-Requested-With': 'XMLHttpRequest', ...extra }
}

async function load(frame) {
	const response = await fetch(davUrl(path), { headers: headers(), credentials: 'same-origin' })
	if (!response.ok) {
		throw new Error(`${response.status} ${response.statusText}`)
	}
	const etag = response.headers.get('ETag')
	if (etag) {
		etags.set(path, etag)
	}
	const bytes = await response.arrayBuffer()
	frame.contentWindow.postMessage({ type: 'gridcraft:open', name: fileName, bytes }, location.origin, [bytes])
	showTitle()
}

/** Store a file GridLogic saved next to the opened one. Resolves to the reply for GridLogic. */
async function store(message) {
	const name = String(message.name ?? '').split(/[\\/]/).pop()
	if (!name) {
		return { type: 'gridcraft:saveFailed', id: message.id, error: 'The file has no name.' }
	}
	if (viaLink && !linkCanEdit) {
		return { type: 'gridcraft:saveFailed', id: message.id, error: 'This link lets you view the workbook, not change it. Download a copy to keep your changes.' }
	}
	if (viaLink && name !== fileName) {
		return { type: 'gridcraft:saveFailed', id: message.id, error: `This link can only save ${fileName}. Download a copy to keep it under another name.` }
	}
	const target = folder ? `${folder}/${name}` : name
	const known = etags.get(target)
	// Overwrite only the version we know; never a file this page didn't open or create.
	const condition = known ? { 'If-Match': known } : { 'If-None-Match': '*' }
	const response = await fetch(davUrl(target), {
		method: 'PUT',
		headers: headers(condition),
		credentials: 'same-origin',
		body: message.bytes,
	})
	if (response.status === 412) {
		const error = known
			? 'Someone else changed this file since you opened it. Save it under another name to keep your changes.'
			: `A file named ${name} already exists in this folder.`
		return { type: 'gridcraft:saveFailed', id: message.id, error }
	}
	if (!response.ok) {
		return { type: 'gridcraft:saveFailed', id: message.id, error: `Nextcloud answered ${response.status} ${response.statusText}.` }
	}
	const etag = response.headers.get('OC-ETag') ?? response.headers.get('ETag')
	if (etag) {
		etags.set(target, etag.startsWith('"') ? etag : `"${etag}"`)
	} else {
		etags.delete(target)
	}
	if (/\.xls[xm]$/i.test(name)) {
		shownName = name
		showTitle()
	}
	return { type: 'gridcraft:saved', id: message.id, name }
}

function start() {
	if (!root || !status) {
		return
	}
	if (!user && !viaLink) {
		show('Sign in to Nextcloud to open files in GridLogic.', true)
		return
	}
	if (!path && !viaLink) {
		show('No file to open. Open a spreadsheet from Files with "Open in GridLogic".', true)
		return
	}
	const editorUrl = new URL(root.dataset.editorUrl, location.href)
	// Added to the page's own query (`v`, the app version), which keeps browser caches current.
	const params = { host: 'parent', ack: '1', author: personName }
	if (!viaLink) {
		// The workbook can carry on in GridLogic on this computer (Open in Desktop App).
		params.desktop = '1'
	}
	for (const [key, value] of Object.entries(params)) {
		editorUrl.searchParams.set(key, value)
	}

	const frame = document.createElement('iframe')
	frame.title = 'GridLogic spreadsheet'
	frame.allow = 'fullscreen; clipboard-read; clipboard-write'
	let opened = false

	addEventListener('message', async (event) => {
		if (event.origin !== location.origin || event.source !== frame.contentWindow) {
			return
		}
		const message = event.data ?? {}
		switch (message.type) {
		case 'gridcraft:ready':
			if (opened) {
				return
			}
			opened = true
			if (!message.ack) {
				console.warn('GridLogic: this build does not confirm saves; a failed save will not be reported')
			}
			try {
				if (message.collab && fileId > 0) {
					await share(frame)
				} else {
					await load(frame)
				}
				status.hidden = true
			} catch (e) {
				show(`Couldn't open ${fileName}: ${e.message}`, true)
			}
			break
		case 'gridcraft:save': {
			let reply
			try {
				// Shared: Save writes the workbook as everyone has it.
				if (collab && /\.xls[xm]$/i.test(String(message.name ?? ''))) {
					await collab.checkpoint()
					reply = { type: 'gridcraft:saved', id: message.id }
					showTitle()
				} else {
					reply = await store(message)
				}
			} catch (e) {
				reply = { type: 'gridcraft:saveFailed', id: message.id, error: e.message }
			}
			frame.contentWindow.postMessage(reply, location.origin)
			break
		}
		case 'gridcraft:collab:op':
			collab?.queue(message.json)
			showTitle()
			break
		case 'gridcraft:collab:checkpointData':
			collab?.received(message)
			break
		case 'gridcraft:cloud': {
			// The Share dialog asks; Nextcloud answers. Guests on a link can only pass the link on.
			shareHandler ??= viaLink
				? guestSharing({ name: shownName, link: `${location.origin}${location.pathname}` })
				: sharing({ path, name: shownName, davUrl: davUrl(path) })
			const reply = await shareHandler(message)
			frame.contentWindow.postMessage(reply, location.origin)
			break
		}
		case 'gridcraft:openDesktop':
			openInDesktop(path)
			notify('Opening in GridLogic on this computer… If nothing happens, install GridLogic for your computer first.')
			break
		case 'gridcraft:collab:selection':
			collab?.setSelection(message.json)
			break
		case 'gridcraft:collab:error':
			console.warn('GridLogic co-editing:', message.error)
			collab?.rejoin()
			break
		case 'gridcraft:dirty':
			dirty = Boolean(message.dirty)
			showTitle()
			break
		case 'gridcraft:failed':
			show(`GridLogic couldn't start: ${message.error}`, true)
			break
		}
	})

	addEventListener('beforeunload', (event) => {
		if (collab ? collab.unsaved : dirty) {
			event.preventDefault()
		}
	})

	frame.src = editorUrl.toString()
	root.appendChild(frame)
}

/**
 * Opens a file of the user's in GridLogic on this computer: a `gridlogic://open` link with this
 * Nextcloud's address and the file's path. The desktop app signs in to this server if it isn't
 * yet, then opens the file in the same co-editing session as this page.
 */
export function openInDesktop(filePath) {
	const server = `${location.origin}${getRootUrl()}`
	location.href = `gridlogic://open?server=${encodeURIComponent(server)}&path=${encodeURIComponent(filePath)}`
}

/** Who's editing, above the editor: each person in the colour they're drawn in on the grid. */
function showPeople(members) {
	let strip = document.getElementById('gridcraft-people')
	if (!strip) {
		strip = document.createElement('div')
		strip.id = 'gridcraft-people'
		strip.setAttribute('aria-label', "Who's editing")
		root.prepend(strip)
	}
	const me = collab?.client
	const sorted = [...members].sort((a, b) => (a.client === me ? -1 : b.client === me ? 1 : a.client - b.client))
	const key = JSON.stringify(sorted.map((m) => [m.client, m.displayName]))
	if (strip.dataset.key === key) {
		return
	}
	strip.dataset.key = key
	strip.replaceChildren(
		Object.assign(document.createElement('span'), { className: 'label', textContent: sorted.length > 1 ? 'Editing now:' : 'Only you are editing' }),
	)
	if (sorted.length < 2) {
		return
	}
	for (const m of sorted) {
		const name = m.displayName || m.userId
		const chip = document.createElement('span')
		chip.className = 'person'
		chip.style.setProperty('--person', colorFor(m.client))
		chip.title = m.client === me ? `${name} (you)` : name
		const initials = Object.assign(document.createElement('span'), { className: 'initials', textContent: initialsOf(name) })
		chip.append(initials, document.createTextNode(m.client === me ? `${name} (you)` : name))
		strip.append(chip)
	}
}

function initialsOf(name) {
	const parts = String(name).trim().split(/\s+/).filter(Boolean)
	return ((parts[0]?.[0] ?? '?') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase()
}

/** Opens the file in a co-editing session: everyone with it open edits the same workbook. */
async function share(frame) {
	collab = new Collab({
		frame,
		fileId,
		fileUrl: davUrl(path),
		headers,
		notify,
		endpoint: viaLink ? generateUrl(`/apps/gridcraft/s/${encodeURIComponent(token)}/collab`) : generateUrl('/apps/gridcraft/collab'),
		joinParams: viaLink ? { displayName: personName } : {},
		onMembers(members) {
			others = Math.max(0, members.length - 1)
			showPeople(members)
			showTitle()
		},
	})
	const { bytes, seq, sheetIds, entries } = await collab.join()
	frame.contentWindow.postMessage({ type: 'gridcraft:open', name: fileName, bytes }, location.origin, [bytes])
	collab.start(seq, entries, sheetIds)
	showTitle()
}

start()
