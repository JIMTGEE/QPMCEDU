const CACHE_NAME = 'queenspa-cache-v1';
const STATIC_ASSETS = [
    '/teacher/dashboard.php',
    '/assets/images/logo.png',
    '/assets/images/default_logo.png',
    '/assets/images/default_avatar.png',
    'https://cdn.tailwindcss.com',
    'https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css',
    'https://code.jquery.com/jquery-3.6.0.min.js',
    'https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap'
];

// Install event: Cache static assets
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(STATIC_ASSETS);
        }).then(() => self.skipWaiting())
    );
});

// Activate event: Clean up old caches
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(cacheNames => {
            return Promise.all(
                cacheNames.filter(name => name !== CACHE_NAME)
                    .map(name => caches.delete(name))
            );
        }).then(() => self.clients.claim())
    );
});

// Fetch event: Serve cached assets or fetch from network
self.addEventListener('fetch', event => {
    event.respondWith(
        caches.match(event.request).then(response => {
            return response || fetch(event.request).catch(() => {
                return caches.match('/teacher/dashboard.php');
            });
        })
    );
});

// Message event: Handle updates to schedules and notifications
self.addEventListener('message', event => {
    if (event.data.type === 'UPDATE_SCHEDULES') {
        saveToIndexedDB('schedules', event.data.schedules);
    } else if (event.data.type === 'UPDATE_NOTIFICATIONS') {
        saveToIndexedDB('notifications', event.data.notifications);
    }
});

// Initialize IndexedDB
function initIndexedDB() {
    return new Promise((resolve, reject) => {
        const request = self.indexedDB.open('QueensPaDB', 1);
        request.onupgradeneeded = event => {
            const db = event.target.result;
            if (!db.objectStoreNames.contains('schedules')) {
                db.createObjectStore('schedules', { keyPath: 'id' });
            }
            if (!db.objectStoreNames.contains('notifications')) {
                db.createObjectStore('notifications', { keyPath: 'id' });
            }
        };
        request.onsuccess = event => resolve(event.target.result);
        request.onerror = event => reject(event.target.error);
    });
}

// Save data to IndexedDB
function saveToIndexedDB(storeName, data) {
    initIndexedDB().then(db => {
        const transaction = db.transaction([storeName], 'readwrite');
        const store = transaction.objectStore(storeName);
        data.forEach(item => store.put(item));
        transaction.oncomplete = () => {
            console.log(`${storeName} saved to IndexedDB in Service Worker`);
            if (storeName === 'schedules') {
                checkSchedules(); // Trigger schedule check after update
            }
        };
        transaction.onerror = err => {
            console.error(`Error saving ${storeName} to IndexedDB: ${err}`);
        };
    }).catch(err => {
        console.error('IndexedDB initialization error in Service Worker: ', err);
    });
}

// Read data from IndexedDB
function readFromIndexedDB(storeName) {
    return initIndexedDB().then(db => {
        return new Promise((resolve, reject) => {
            const transaction = db.transaction([storeName], 'readonly');
            const store = transaction.objectStore(storeName);
            const request = store.getAll();
            request.onsuccess = () => resolve(request.result);
            request.onerror = () => reject(request.error);
        });
    });
}

// Check schedules and trigger notifications
function checkSchedules() {
    readFromIndexedDB('schedules').then(schedules => {
        const now = new Date();
        const currentDay = now.toLocaleString('en-US', { weekday: 'long' });
        const currentTime = now.getHours() * 60 + now.getMinutes();

        schedules.forEach(schedule => {
            if (schedule.day_of_week === currentDay) {
                const [hours, minutes] = schedule.start_time.split(':').map(Number);
                const scheduleTime = hours * 60 + minutes;
                const timeDiff = scheduleTime - currentTime;

                // Trigger notification 5 minutes before class
                if (timeDiff > 0 && timeDiff <= 5) {
                    showNotification(
                        `Class Reminder: ${schedule.class_name}`,
                        `Your ${schedule.subject_name} class with ${schedule.class_name} starts at ${schedule.start_time} in 5 minutes.`,
                        '/teacher/class_schedule.php'
                    );
                }
            }
        });
    }).catch(err => {
        console.error('Error reading schedules from IndexedDB: ', err);
    });
}

// Show browser notification
function showNotification(title, body, url) {
    self.registration.showNotification(title, {
        body: body,
        icon: '/assets/images/logo.png',
        badge: '/assets/images/logo.png',
        data: { url: url },
        tag: `class-reminder-${Date.now()}`, // Unique tag to avoid duplicates
        renotify: true,
        requireInteraction: true
    });
}

// Handle notification click
self.addEventListener('notificationclick', event => {
    event.notification.close();
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(clientsArr => {
            const url = event.notification.data.url;
            const client = clientsArr.find(c => c.url === url && 'focus' in c);
            if (client) {
                return client.focus();
            } else if (clientsArr.length) {
                return clientsArr[0].focus();
            } else {
                return clients.openWindow(url);
            }
        })
    );
});

// Periodic schedule check (every minute)
setInterval(checkSchedules, 60000);

// Show cached notifications when offline
self.addEventListener('periodicsync', event => {
    if (event.tag === 'sync-notifications') {
        event.waitUntil(
            readFromIndexedDB('notifications').then(notifications => {
                notifications.forEach(notification => {
                    showNotification(
                        notification.title,
                        notification.message,
                        notification.link || '/teacher/notifications.php'
                    );
                });
            }).catch(err => {
                console.error('Error syncing notifications: ', err);
            })
        );
    }
});