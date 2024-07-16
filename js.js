function uploadFiles() {
    const files = document.getElementById('fileInput').files;
    const progressBar = document.getElementById('progressBar');
    const status = document.getElementById('status');
    const fileTableBody = document.getElementById('fileTableBody');

    if (files.length === 0) {
        alert('Please select a file.');
        return;
    }

    const uploadFile = function(file, index) {
        return new Promise((resolve, reject) => {
            const xhr = new XMLHttpRequest();
            const formData = new FormData();
            formData.append('files[]', file);

            xhr.open('POST', 'upload.php', true);

            xhr.upload.onprogress = function(event) {
                if (event.lengthComputable) {
                    const percentComplete = (event.loaded / event.total) * 100;
                    progressBar.style.width = percentComplete + '%';
                }
            };

            xhr.onload = function() {
                if (xhr.status === 200) {
                    const response = JSON.parse(xhr.responseText);
                    if (response.success) {
                        document.getElementById(`status-${index}`).textContent = 'Uploaded';
                        document.getElementById(`file-link-${index}`).href = response.filePaths[index];
                        resolve();
                    } else {
                        document.getElementById(`status-${index}`).textContent = 'Failed';
                        reject();
                    }
                } else {
                    document.getElementById(`status-${index}`).textContent = 'Failed';
                    reject();
                }
            };

            xhr.onerror = function() {
                document.getElementById(`status-${index}`).textContent = 'Failed';
                reject();
            };

            xhr.send(formData);
        });
    };

    fileTableBody.innerHTML = '';
    for (let i = 0; i < files.length; i++) {
        const row = document.createElement('tr');
        row.innerHTML = `
            <td>${i + 1}</td>
            <td>${files[i].name}</td>
            <td id="status-${i}">Uploading...</td>
        `;
        fileTableBody.appendChild(row);

        uploadFile(files[i], i).then(() => {
            status.textContent = 'All files uploaded successfully!';
        }).catch(() => {
            status.textContent = 'Upload failed. Please try again.';
        });
    }
}
