FROM python:3.12-slim
WORKDIR /app
COPY requirements.txt .
RUN pip install --no-cache-dir -r requirements.txt
COPY generator.py orders.py app.py ./
COPY templates ./templates
COPY static ./static
EXPOSE 8092
CMD ["waitress-serve", "--host=0.0.0.0", "--port=8092", "--threads=8", "app:app"]
