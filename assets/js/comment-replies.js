function getRepliesButton(replies) {
  return Array.from(document.querySelectorAll('[data-comment-replies-toggle]'))
    .find((button) => button.getAttribute('aria-controls') === replies.id) || null;
}

function openReplies(replies, button = getRepliesButton(replies)) {
  replies.hidden = false;
  replies.classList.remove('comment-replies--collapsed');
  replies.classList.add('comment-replies--expanded');

  if (button) {
    button.setAttribute('aria-expanded', 'true');
    button.hidden = true;
  }
}

function getHashTarget() {
  if (!window.location.hash || window.location.hash.length < 2) {
    return null;
  }

  return document.getElementById(window.location.hash.slice(1));
}

function openRepliesForCurrentHash() {
  const target = getHashTarget();

  if (!target) {
    return;
  }

  const replies = target.closest('[data-comment-replies]');

  if (replies) {
    openReplies(replies);
  }
}

export function initCommentReplies() {
  document.querySelectorAll('[data-comment-replies]').forEach((replies) => {
    const button = getRepliesButton(replies);
    const hashTarget = getHashTarget();

    if (hashTarget && replies.contains(hashTarget)) {
      openReplies(replies, button);

      return;
    }

    replies.hidden = true;
    replies.classList.add('comment-replies--collapsed');

    if (button) {
      button.setAttribute('aria-expanded', 'false');
      button.addEventListener('click', (event) => {
        event.preventDefault();
        openReplies(replies, button);
      });
    }
  });

  window.addEventListener('hashchange', openRepliesForCurrentHash);
}
