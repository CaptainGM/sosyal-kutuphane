
const TMDB_API_KEY = '23623d25826824baf3e05d2ea9870b27';
const TMDB_BASE_URL = 'https://api.themoviedb.org/3';
const GOOGLE_BOOKS_BASE_URL = 'https://www.googleapis.com/books/v1/volumes';
const API_BASE_URL = '/api';
const fetchOptions = {
    credentials: 'include'
};

class SocialLibraryApp {
    constructor() {
        this.currentUser = null;
        this.initPromise = this.init();
    }

    async init() {
        try {
            const response = await fetch(`${API_BASE_URL}/auth-status.php`, {
                credentials: 'include'
            });
            const data = await response.json();

            if (data.success && data.user) {
                this.currentUser = data.user;
                console.log('✅ User logged in:', this.currentUser.username);
            } else {
                this.currentUser = null;
                console.log('Not authenticated');
            }
        } catch (error) {
            console.error('Auth check failed:', error);
            this.currentUser = null;
        }
    }

    async searchMovies(query, page = 1) {
        try {
            const response = await fetch(
                `${TMDB_BASE_URL}/search/movie?api_key=${TMDB_API_KEY}&query=${encodeURIComponent(query)}&language=tr-TR&page=${page}`
            );
            return await response.json();
        } catch (error) {
            console.error('Film arama hatası:', error);
            return { results: [] };
        }
    }

    async getMovieDetail(movieId) {
        try {
            const response = await fetch(
                `${TMDB_BASE_URL}/movie/${movieId}?api_key=${TMDB_API_KEY}&language=tr-TR&append_to_response=credits`
            );
            return await response.json();
        } catch (error) {
            console.error('Film detay hatası:', error);
            return null;
        }
    }

    async getTopRatedMovies() {
        try {
            const response = await fetch(
                `${TMDB_BASE_URL}/movie/top_rated?api_key=${TMDB_API_KEY}&language=tr-TR&page=1`
            );
            const data = await response.json();
            return data.results.slice(0, 12);
        } catch (error) {
            console.error('En iyi filmler hatası:', error);
            return [];
        }
    }

    async getPopularMovies() {
        try {
            const response = await fetch(
                `${TMDB_BASE_URL}/movie/popular?api_key=${TMDB_API_KEY}&language=tr-TR&page=1`
            );
            const data = await response.json();
            return data.results.slice(0, 12);
        } catch (error) {
            console.error('Popüler filmler hatası:', error);
            return [];
        }
    }

    async searchBooks(query) {
        try {
            const url = `${GOOGLE_BOOKS_BASE_URL}?q=intitle:${encodeURIComponent(query)}&maxResults=40&orderBy=relevance`;
            const response = await fetch(url);
            if (!response.ok) throw new Error(`Google Books API error: ${response.status}`);
            const data = await response.json();
            let items = data.items || [];
            const queryLower = query.toLowerCase();

            items.sort((a, b) => {
                const titleA = (a.volumeInfo?.title || '').toLowerCase();
                const titleB = (b.volumeInfo?.title || '').toLowerCase();
                if (titleA === queryLower && titleB !== queryLower) return -1;
                if (titleB === queryLower && titleA !== queryLower) return 1;
                const containsA = titleA.includes(queryLower);
                const containsB = titleB.includes(queryLower);
                if (containsA && !containsB) return -1;
                if (containsB && !containsA) return 1;

                return 0;
            });

            return { items };
        } catch (error) {
            console.error('Kitap arama hatası:', error);
            return { items: [] };
        }
    }

    async getBookDetail(bookId) {
        try {
            const url = `${GOOGLE_BOOKS_BASE_URL}/${bookId}`;
            const response = await fetch(url);
            if (!response.ok) throw new Error(`Google Books detail error: ${response.status}`);
            return await response.json();
        } catch (error) {
            console.error('Kitap detay hatası:', error);
            return null;
        }
    }
    async saveContentStatus(contentType, contentId, rating = 0, notes = '') {
        if (!this.currentUser) {
            alert('Lütfen giriş yapın');
            return false;
        }

        try {
            const response = await fetch(`${API_BASE_URL}/save-content-status.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    user_id: this.currentUser.id,
                    content_type: contentType,
                    content_id: contentId,
                    rating: rating,
                    notes: notes
                })
            });

            const data = await response.json();
            if (data.success) {
                console.log('✅ İçerik kaydedildi');
                return true;
            }
            return false;
        } catch (error) {
            console.error('İçerik kaydetme hatası:', error);
            return false;
        }
    }
    async addComment(contentType, contentId, commentText, contentTitle = '') {
        if (!this.currentUser) {
            alert('Lütfen giriş yapın');
            return false;
        }

        try {
            const response = await fetch(`${API_BASE_URL}/comments.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    action: 'add',
                    user_id: this.currentUser.id,
                    content_type: contentType,
                    content_id: contentId,
                    comment_text: commentText,
                    content_title: contentTitle
                })
            });

            const data = await response.json();
            if (data.success) {
                console.log('✅ Yorum eklendi');
                return true;
            }
            return false;
        } catch (error) {
            console.error('Yorum ekleme hatası:', error);
            return false;
        }
    }
    async deleteComment(commentId) {
        if (!this.currentUser) return false;

        try {
            const response = await fetch(`${API_BASE_URL}/comments.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    action: 'delete',
                    comment_id: commentId,
                    user_id: this.currentUser.id
                })
            });

            const data = await response.json();
            return data.success;
        } catch (error) {
            console.error('Yorum silme hatası:', error);
            return false;
        }
    }
    async likeComment(commentId) {
        if (!this.currentUser) {
            alert('Lütfen giriş yapın');
            return false;
        }

        try {
            const response = await fetch(`${API_BASE_URL}/like-comment.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    comment_id: commentId,
                    user_id: this.currentUser.id
                })
            });

            const data = await response.json();
            return data.success;
        } catch (error) {
            console.error('Beğen hatası:', error);
            return false;
        }
    }
    async getComments(contentType, contentId) {
        try {
            const response = await fetch(
                `${API_BASE_URL}/comments.php?action=get&content_type=${contentType}&content_id=${contentId}`,
                { credentials: 'include' }
            );
            const data = await response.json();
            return data.comments || [];
        } catch (error) {
            console.error('Yorum getirme hatası:', error);
            return [];
        }
    }
    async followUser(targetUserId) {
        if (!this.currentUser) {
            alert('Lütfen giriş yapın');
            return false;
        }

        try {
            const response = await fetch(`${API_BASE_URL}/follow.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    follower_id: this.currentUser.id,
                    followed_id: targetUserId
                })
            });

            const data = await response.json();
            return data.success;
        } catch (error) {
            console.error('Takip hatası:', error);
            return false;
        }
    }
    async unfollowUser(targetUserId) {
        if (!this.currentUser) return false;

        try {
            const response = await fetch(`${API_BASE_URL}/follow.php`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                credentials: 'include',
                body: JSON.stringify({
                    action: 'unfollow',
                    follower_id: this.currentUser.id,
                    followed_id: targetUserId
                })
            });

            const data = await response.json();
            return data.success;
        } catch (error) {
            console.error('Takip bırakma hatası:', error);
            return false;
        }
    }
    async isFollowing(targetUserId) {
        if (!this.currentUser) return false;

        try {
            const response = await fetch(
                `${API_BASE_URL}/follow-status.php?follower_id=${this.currentUser.id}&followed_id=${targetUserId}`,
                { credentials: 'include' }
            );
            const data = await response.json();
            return data.is_following;
        } catch (error) {
            console.error('Takip durumu kontrol hatası:', error);
            return false;
        }
    }
    async getRating(contentType, contentId) {
        if (!this.currentUser) return null;

        try {
            const response = await fetch(
                `${API_BASE_URL}/user-status.php?content_type=${contentType}&content_id=${contentId}`,
                { method: 'GET', credentials: 'include' }
            );
            const data = await response.json();
            return data.success ? data.rating : null;
        } catch (error) {
            console.error('Puan getirme hatası:', error);
            return null;
        }
    }

    getTimeAgo(timestamp) {
        const now = new Date();
        const past = new Date(timestamp);
        const seconds = Math.floor((now - past) / 1000);

        if (seconds < 60) return 'biraz önce';
        if (seconds < 3600) return `${Math.floor(seconds / 60)} dakika önce`;
        if (seconds < 86400) return `${Math.floor(seconds / 3600)} saat önce`;
        if (seconds < 2592000) return `${Math.floor(seconds / 86400)} gün önce`;
        return `${Math.floor(seconds / 2592000)} ay önce`;
    }

    formatDate(timestamp) {
        const date = new Date(timestamp);
        const now = new Date();
        const diffMs = now - date;
        const diffSec = Math.floor(diffMs / 1000);
        const diffMin = Math.floor(diffSec / 60);
        const diffHour = Math.floor(diffMin / 60);
        const diffDay = Math.floor(diffHour / 24);

        if (diffSec < 60) {
            return 'Az önce';
        } else if (diffMin < 60) {
            return `${diffMin} dakika önce`;
        } else if (diffHour < 24) {
            return `${diffHour} saat önce`;
        } else if (diffDay < 7) {
            return `${diffDay} gün önce`;
        } else {
            return date.toLocaleDateString('tr-TR');
        }
    }

    ratingToStars(rating) {
        const stars = Math.round(rating / 2);
        return '⭐'.repeat(stars);
    }

    logout() {
        this.currentUser = null;
    }
}
const app = new SocialLibraryApp();
async function logout() {
    try {
        await fetch(`${API_BASE_URL}/logout.php`, { credentials: 'include' });
    } catch (e) { }
    app.logout();
    window.location.href = 'index.html';
}

