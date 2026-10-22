document.addEventListener("DOMContentLoaded", function () {
    const starField = document.createElement('div');
    starField.className = 'star-field';

    
    const maxPageHeight = Math.max(
        document.body.scrollHeight, 
        document.documentElement.scrollHeight,
        document.body.offsetHeight, 
        document.documentElement.offsetHeight,
        document.body.clientHeight, 
        document.documentElement.clientHeight
    );
	const totalStars = maxPageHeight / 7;
    for (let i = 0; i < totalStars; i++) {
        const star = document.createElement('div');
        star.className = 'star';
        star.style.width = `${Math.random() * 3}px`;
        star.style.height = star.style.width;
        star.style.top = `${Math.random() * maxPageHeight * 0.99}px`;
        star.style.left = `${Math.random() * 99}%`;
        starField.appendChild(star);
    }

    document.body.appendChild(starField);
});
